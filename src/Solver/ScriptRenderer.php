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

    /** The sum of the first `n` cells of an array, for `array_sum()` over a `list<int>`. */
    private const LIST_SUM = '(define-fun-rec sum_List_Int ((a (Array Int Int)) (n Int)) Int (ite (<= n 0) 0 (+ (sum_List_Int a (- n 1)) (select a (- n 1)))))';

    /**
     * Z3 answers the checks of a script with `push`/`pop` with its incremental solver, which with
     * quantifiers gives up at the timeout. This hands a check to the other solver after 1000 ms.
     * Both are sound: the option changes which checks are answered, never what an answer means.
     */
    private const SOLVER_FALLBACK = '(set-option :combined_solver.solver2_timeout 1000)';

    public function checkCount(Encoding $encoding): int
    {
        return 1 + count($encoding->obligations) + count($encoding->ensures) + count($encoding->throwsChecks) + ($encoding->returnTypeCheck === null ? 0 : 1);
    }

    public function render(Encoding $encoding): string
    {
        $lines = ['(set-option :produce-models true)'];
        if ($encoding->needsSolverFallback) {
            $lines[] = self::SOLVER_FALLBACK;
        }
        array_push($lines, ...$this->declarations($encoding));

        $lines[] = self::PRELUDE;

        $commands = $encoding->commands();
        $boundary = $encoding->assumptionBoundary() ?? count($commands);
        array_push($lines, ...array_slice($commands, 0, $boundary));

        $index = 0;
        array_push($lines, sprintf('(echo "@@%d")', $index++), '(check-sat)');
        array_push($lines, ...array_slice($commands, $boundary));

        $observed = implode(' ', $encoding->observedTerms());

        foreach ($this->violations($encoding) as [$violation, $assumed]) {
            array_push($lines, ...$this->check($index++, $violation, $observed));
            if ($assumed !== null) {
                $lines[] = '(assert ' . $assumed->smt . ')';
            }
        }

        return implode("\n", $lines) . "\n";
    }

    /**
     * A script with one check, number 0, that asks again for the check `$check` of {@see self::render()}
     * with the length of every list in the model bounded by `$maxLength`. It holds what the main
     * script assumes before that check: the commands, and the obligations that come earlier. A
     * model of it is therefore a model of the main check, only with shorter lists.
     */
    public function renderBoundedCheck(Encoding $encoding, int $check, int $maxLength): string
    {
        $lines = ['(set-option :produce-models true)', ...$this->declarations($encoding), self::PRELUDE, ...$encoding->commands()];

        // Check 0 is the satisfiability of the assumptions, so the check n is the violation n - 1.
        $violations = $this->violations($encoding);
        foreach (array_slice($violations, 0, max(0, $check - 1)) as [, $assumed]) {
            if ($assumed !== null) {
                $lines[] = '(assert ' . $assumed->smt . ')';
            }
        }

        [$violation] = $violations[$check - 1] ?? throw new \LogicException('There is no check number ' . $check . '.');
        $lines[] = '(echo "@@0")';
        $lines[] = '(push 1)';
        $lines[] = '(assert ' . $violation->smt . ')';
        foreach ($encoding->lengthProbes() as $length) {
            $lines[] = sprintf('(assert (<= %s %d))', $length, $maxLength);
        }
        $lines[] = '(check-sat)';
        $lines[] = '(get-value (' . implode(' ', $encoding->observedTerms()) . '))';
        $lines[] = '(pop 1)';

        return implode("\n", $lines) . "\n";
    }

    /**
     * A script with one check, number 0, that asks again for the check `$check` of {@see self::render()},
     * alone and with Z3's default options. It holds what the main script assumes before that check: the
     * commands, and the obligations that come earlier, so its answer is the answer to the same question.
     */
    public function renderSingleCheck(Encoding $encoding, int $check): string
    {
        $lines = ['(set-option :produce-models true)', ...$this->declarations($encoding), self::PRELUDE];
        $commands = $encoding->commands();
        $observed = implode(' ', $encoding->observedTerms());
        if ($check === 0) {
            $boundary = $encoding->assumptionBoundary() ?? count($commands);
            array_push($lines, ...array_slice($commands, 0, $boundary));
            array_push($lines, '(echo "@@0")', '(push 1)', '(check-sat)', "(get-value ($observed))", '(pop 1)');
            return implode("\n", $lines) . "\n";
        }
        array_push($lines, ...$commands);
        $violations = $this->violations($encoding);
        foreach (array_slice($violations, 0, $check - 1) as [, $assumed]) {
            if ($assumed !== null) {
                $lines[] = '(assert ' . $assumed->smt . ')';
            }
        }
        [$violation] = $violations[$check - 1] ?? throw new \LogicException('There is no check number ' . $check . '.');
        array_push($lines, ...$this->check(0, $violation, $observed));
        return implode("\n", $lines) . "\n";
    }

    /**
     * What each check after the satisfiability of the assumptions asks Z3 to find, in script
     * order, and for an obligation what holds once it has been checked.
     *
     * @return list<array{Term, Term|null}> the violation, and the assumption that follows an obligation
     */
    private function violations(Encoding $encoding): array
    {
        $violations = [];
        foreach ($encoding->obligations as $obligation) {
            $violations[] = [
                Smt::all($obligation->pathCondition, Smt::not($obligation->goal)),
                Smt::implies($obligation->pathCondition, $obligation->goal),
            ];
        }
        foreach ($encoding->ensures as $ensures) {
            $violations[] = [Smt::not($ensures->term), null];
        }
        foreach ($encoding->throwsChecks as $throws) {
            $violations[] = [$throws->violation, null];
        }
        if ($encoding->returnTypeCheck !== null) {
            $check = $encoding->returnTypeCheck;
            $violations[] = [Smt::all($check->pathCondition, Smt::not($check->goal)), null];
        }

        return $violations;
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
        if ($encoding->listSumRequired()) {
            $lines[] = self::LIST_SUM;
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
