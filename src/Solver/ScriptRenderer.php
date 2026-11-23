<?php

declare(strict_types=1);

namespace PhpstanContracts\Solver;

use PhpstanContracts\Smt\EnumSort;
use PhpstanContracts\Smt\Smt;
use PhpstanContracts\Smt\Sort;
use PhpstanContracts\Smt\SortKind;
use PhpstanContracts\Smt\Term;
use PhpstanContracts\Translate\Encoding;

/**
 * Renders an {@see Encoding} as one SMT-LIB script containing every check.
 *
 * Checks come out in a fixed order, each introduced by an `@@<index>` marker:
 * satisfiability of the assumptions, then each obligation, then each postcondition,
 * then each `#[Throws]` condition, then the return type.
 * An obligation that has been checked is assumed for the checks after it, so one
 * mistake is reported once instead of cascading.
 */
final class ScriptRenderer
{
    private const PRELUDE = <<<'SMT'
        (define-fun php_abs ((x Int)) Int (ite (>= x 0) x (- x)))
        (define-fun php_intdiv ((a Int) (b Int)) Int
          (ite (>= a 0)
               (ite (>= b 0) (div a b) (- (div a (- b))))
               (ite (>= b 0) (- (div (- a) b)) (div (- a) (- b)))))
        (define-fun php_mod ((a Int) (b Int)) Int
          (ite (>= a 0) (mod a (php_abs b)) (- (mod (- a) (php_abs b)))))
        (define-fun php_int_to_string ((n Int)) String
          (ite (>= n 0) (str.from_int n) (str.++ "-" (str.from_int (- n)))))
        SMT;

    public function checkCount(Encoding $encoding): int
    {
        return 1 + count($encoding->obligations) + count($encoding->ensures) + count($encoding->throwsChecks) + ($encoding->returnTypeCheck === null ? 0 : 1);
    }

    public function render(Encoding $encoding): string
    {
        $lines = ['(set-option :produce-models true)', ...$this->declarations($encoding)];

        $lines[] = self::PRELUDE;

        $commands = $encoding->commands();
        $boundary = $encoding->assumptionBoundary() ?? count($commands);
        array_push($lines, ...array_slice($commands, 0, $boundary));

        $index = 0;
        array_push($lines, sprintf('(echo "@@%d")', $index++), '(check-sat)');
        array_push($lines, ...array_slice($commands, $boundary));

        $observed = implode(' ', $encoding->observedTerms());

        foreach ($encoding->obligations as $obligation) {
            $violation = Smt::all($obligation->pathCondition, Smt::not($obligation->goal));
            array_push($lines, ...$this->check($index++, $violation, $observed));
            $lines[] = '(assert ' . Smt::implies($obligation->pathCondition, $obligation->goal)->smt . ')';
        }

        foreach ($encoding->ensures as $ensures) {
            array_push($lines, ...$this->check($index++, Smt::not($ensures->term), $observed));
        }

        foreach ($encoding->throwsChecks as $throws) {
            array_push($lines, ...$this->check($index++, $throws->violation, $observed));
        }

        if ($encoding->returnTypeCheck !== null) {
            $check = $encoding->returnTypeCheck;
            array_push($lines, ...$this->check($index++, Smt::all($check->pathCondition, Smt::not($check->goal)), $observed));
        }

        return implode("\n", $lines) . "\n";
    }

    /**
     * The datatypes of the encoding, then the functions on its enums.
     *
     * @return list<string>
     */
    public function declarations(Encoding $encoding): array
    {
        $lines = [];
        foreach ($encoding->datatypes() as $sort) {
            $lines[] = $this->datatypeDeclaration($sort);
        }
        foreach ($encoding->enumFunctions() as [$enum, $function]) {
            $lines[] = $this->enumFunction($enum, $function);
        }

        return $lines;
    }

    /**
     * @return list<string>
     */
    private function check(int $index, Term $assumption, string $observed): array
    {
        return [
            sprintf('(echo "@@%d")', $index),
            '(push 1)',
            '(assert ' . $assumption->smt . ')',
            '(check-sat)',
            "(get-value ($observed))",
            '(pop 1)',
        ];
    }

    private function datatypeDeclaration(Sort $sort): string
    {
        if ($sort->kind === SortKind::Enum && $sort->enum !== null) {
            $constructors = array_map(
                static fn (int|string $case): string => '(' . $sort->enum->constructorOf((string) $case) . ')',
                array_keys($sort->enum->cases),
            );

            return sprintf('(declare-datatypes ((%s 0)) ((%s)))', $sort->smt, implode(' ', $constructors));
        }

        if ($sort->kind === SortKind::Nullable && $sort->inner !== null) {
            return sprintf(
                '(declare-datatypes ((%1$s 0)) (((none_%1$s) (some_%1$s (val_%1$s %2$s)))))',
                $sort->smt,
                $sort->inner->smt,
            );
        }

        if ($sort->kind === SortKind::Record && $sort->record !== null) {
            $fields = [];
            foreach ($sort->record->fields as $name => $fieldSort) {
                $fields[] = sprintf('(%s %s)', $sort->record->accessor((string) $name), $fieldSort->smt);
            }

            return sprintf(
                '(declare-datatypes ((%s 0)) (((%s %s))))',
                $sort->smt,
                $sort->record->constructorName(),
                implode(' ', $fields),
            );
        }

        if ($sort->kind === SortKind::List && $sort->element !== null) {
            return sprintf(
                '(declare-datatypes ((%1$s 0)) (((mk_%1$s (%1$s_len Int) (%1$s_items (Array Int %2$s))))))',
                $sort->smt,
                $sort->element->smt,
            );
        }
        if ($sort->kind === SortKind::Shape && $sort->shape !== null) {
            $fields = [];
            foreach (array_values($sort->shape->fields) as $position => $fieldSort) {
                $fields[] = sprintf('(%s %s)', $sort->shape->accessorAt($position), $fieldSort->smt);
            }

            return sprintf(
                '(declare-datatypes ((%s 0)) (((%s %s))))',
                $sort->smt,
                $sort->shape->constructorName(),
                implode(' ', $fields),
            );
        }

        throw new \LogicException('No datatype declaration for sort ' . $sort->smt);
    }

    /**
     * @param 'value'|'name' $function
     */
    private function enumFunction(EnumSort $enum, string $function): string
    {
        $isName = $function === 'name';
        $returns = $isName || $enum->backingType === 'string' ? Sort::string() : Sort::int();

        $literals = [];
        foreach ($enum->cases as $case => $backing) {
            $literals[(string) $case] = match (true) {
                $isName => Smt::stringLiteral((string) $case)->smt,
                is_int($backing) => Smt::int($backing)->smt,
                default => Smt::stringLiteral((string) $backing)->smt,
            };
        }

        $lastCase = array_key_last($literals);
        $body = $literals[$lastCase];
        unset($literals[$lastCase]);

        foreach (array_reverse($literals, true) as $case => $literal) {
            $body = sprintf('(ite (= e %s) %s %s)', $enum->constructorOf((string) $case), $literal, $body);
        }

        return sprintf(
            '(define-fun %s ((e %s)) %s %s)',
            $isName ? $enum->nameFunction() : $enum->valueFunction(),
            $enum->smtName,
            $returns->smt,
            $body,
        );
    }
}
