<?php

declare(strict_types=1);

namespace PhpstanContracts\Translate;

use PhpstanContracts\Smt\EnumSort;
use PhpstanContracts\Smt\Smt;
use PhpstanContracts\Smt\Sort;
use PhpstanContracts\Smt\SortKind;
use PhpstanContracts\Smt\Term;
use LogicException;

/**
 * Everything the solver needs for one method: declarations and definitions in
 * order, the safety obligations, and the postconditions to refute.
 */
final class Encoding
{
    /** @var list<Obligation> */
    public array $obligations = [];

    /** @var list<EnsuresCheck> */
    public array $ensures = [];

    /**
     * Checked after the postconditions and never assumed by them, so that a bad
     * return value is reported through the contract that states why it is bad.
     */
    public ?Obligation $returnTypeCheck = null;

    /** @var list<ThrowsCheck> */
    public array $throwsChecks = [];

    /** @var list<NamedSymbol> */
    public array $inputs = [];

    public ?NamedSymbol $result = null;

    /** @var list<NamedSymbol> */
    public array $returnMarkers = [];

    private int $counter = 0;

    /** @var list<array{int, int, int}> the loops being translated: line, iteration and bound */
    private array $loops = [];

    /** @var array<int, LoopBound> the loops the script unrolls, by line */
    private array $unrolledLoops = [];

    /** @var array<'product'|'division'|'modulo', string> the first operation of each non-linear kind, as written */
    private array $nonLinearOperations = [];

    /** @var array<int, int> the nesting depth of each unrolled loop by line, 0 for an outermost one */
    private array $loopDepths = [];

    /** @var int the copies of a loop body that the script holds, the iterations of the inner loops counted in each iteration of the outer ones */
    private int $unrolledIterations = 0;

    /** @var int the non-linear operations met while translating, once per copy of a loop body */
    private int $nonLinearCount = 0;

    /** @var int the divisions and modulos by a variable met inside an unrolled loop, once per copy of its body */
    private int $divisionsInLoops = 0;

    /** @var array{string, int, string}|null the length of the list that drives the outermost unrolling, its bound and the list as written */
    private ?array $splitLength = null;

    /** Whether the script holds a loop, which gets the solver option of the retry. */
    public bool $needsSolverFallback = false;

    /** Whether `sum_List_Int` is used, so that the script declares it and the range lemma. */
    private bool $listSumRequired = false;

    /** @var list<array{Term, int, int}> input lists of ints with both bounds: the list and its bounds */
    private array $boundedInputLists = [];

    /** @var array<string, Sort> datatypes in the order they must be declared */
    private array $datatypes = [];

    /** @var array<string, array{EnumSort, 'value'|'name'}> */
    private array $enumFunctions = [];

    /** @var list<Term> */
    private array $returnConditions = [];

    /** @var list<string> */
    private array $commands = [];

    private ?int $assumptionBoundary = null;

    /**
     * Records a sort that needs a datatype declaration, after the sorts it depends on.
     */
    public function registerSort(Sort $sort): void
    {
        $registered = $this->datatypes[$sort->smt] ?? null;
        if ($registered !== null) {
            if (
                $registered->enum?->className !== $sort->enum?->className
                || $registered->record?->className !== $sort->record?->className
                || $registered->shape?->keys() !== $sort->shape?->keys()
            ) {
                throw new LogicException(sprintf('Two classes share the SMT sort %s.', $sort->smt));
            }

            return;
        }

        switch ($sort->kind) {
            case SortKind::Enum:
                break;
            case SortKind::Nullable:
                if ($sort->inner !== null) {
                    $this->registerSort($sort->inner);
                }
                break;
            case SortKind::Record:
                if ($sort->record !== null) {
                    foreach ($sort->record->fields as $field) {
                        $this->registerSort($field);
                    }
                }
                break;
            case SortKind::List:
                if ($sort->element !== null) {
                    $this->registerSort($sort->element);
                }
                break;
            case SortKind::Shape:
                if ($sort->shape !== null) {
                    foreach ($sort->shape->fields as $field) {
                        $this->registerSort($field);
                    }
                }
                break;
            default:
                return;
        }

        $this->datatypes[$sort->smt] = $sort;
    }

    /**
     * @param 'value'|'name' $function
     */
    public function requireEnumFunction(EnumSort $enum, string $function): void
    {
        $this->enumFunctions[$enum->smtName . ':' . $function] = [$enum, $function];
    }

    /**
     * Notes the input lists of ints whose elements have both bounds, which get the range
     * lemma of `array_sum()` (see {@see self::requireListSum()}). Looks through records
     * and array shapes, not through nullables or nested lists.
     */
    public function registerInput(Term $term, VarSpec $spec): void
    {
        switch ($spec->sort->kind) {
            case SortKind::List:
                if ($spec->element !== null && $spec->element->sort->isInt() && $spec->element->min !== null && $spec->element->max !== null) {
                    $this->boundedInputLists[] = [$term, $spec->element->min, $spec->element->max];
                }
                break;
            case SortKind::Record:
                foreach ($spec->fields as $name => $field) {
                    $this->registerInput(Smt::field($term, (string) $name), $field);
                }
                break;
            case SortKind::Shape:
                foreach ($spec->fields as $key => $field) {
                    $this->registerInput(Smt::shapeField($term, (string) $key), $field);
                }
                break;
            default:
                break;
        }
    }

    /**
     * `array_sum()` over a `list<int>` is used: the script declares `sum_List_Int`, and asserts
     * for every input list of bounded ints that the sum of its first `n` elements is between
     * `n * lo` and `n * hi`. That is a theorem of the domain (it follows by induction, which Z3
     * does not do on its own), so it adds no assumption.
     */
    public function requireListSum(): void
    {
        $this->needsSolverFallback = true;
        if ($this->listSumRequired) {
            return;
        }
        $this->listSumRequired = true;
        foreach ($this->boundedInputLists as [$list, $min, $max]) {
            $this->registerSort($list->sort);
            $count = Smt::symbol('n!0', Sort::int());
            $sum = Smt::prefixSum($list, $count);
            $this->assert(Smt::forall('n!0', Smt::implies(
                Smt::all(Smt::compare('<=', Smt::int(0), $count), Smt::compare('<=', $count, Smt::listLength($list))),
                Smt::all(
                    Smt::compare('<=', Smt::arithmetic('*', Smt::int($min), $count), $sum),
                    Smt::compare('<=', $sum, Smt::arithmetic('*', Smt::int($max), $count)),
                ),
            ), [$sum]));
        }
    }

    public function listSumRequired(): bool
    {
        return $this->listSumRequired;
    }

    public function fresh(string $hint, Sort $sort): Term
    {
        if ($sort->containsNull()) {
            throw new LogicException('A value whose type contains the null literal type cannot be declared: ' . $sort->describe());
        }
        $this->registerSort($sort);

        $name = preg_replace('/[^A-Za-z0-9_]/', '_', $hint) . '@' . ++$this->counter;
        $this->commands[] = sprintf('(declare-const %s %s)', $name, $sort->smt);

        return Smt::symbol($name, $sort);
    }

    public function define(string $hint, Term $value): Term
    {
        if ($value->atomic) {
            // `none_Opt_List_Int` is an atom whose datatype nothing else may have declared.
            $this->registerSort($value->sort);

            return $value;
        }

        $symbol = $this->fresh($hint, $value->sort);
        $this->assert(Smt::eq($symbol, $value));

        return $symbol;
    }

    public function assert(Term $term): void
    {
        if (!$term->isTrue()) {
            $this->commands[] = '(assert ' . $term->smt . ')';
        }
    }

    public function declareInput(string $phpName, Sort $sort): Term
    {
        $term = $this->fresh($phpName, $sort);
        $this->inputs[] = new NamedSymbol($term->smt, $phpName, $sort);

        return $term;
    }

    public function declareResult(Sort $sort): Term
    {
        $term = $this->fresh('result', $sort);
        $this->result = new NamedSymbol($term->smt, 'result', $sort);

        return $term;
    }

    public function recordReturn(Term $reached, Term $value, int $line): void
    {
        if ($this->result === null) {
            throw new LogicException('Result must be declared before recording returns.');
        }

        $this->assert(Smt::implies(
            $reached,
            Smt::eq(Smt::symbol($this->result->smtName, $this->result->sort), $value),
        ));

        $this->returnConditions[] = $reached;

        $marker = $this->fresh('return', Sort::bool());
        $this->assert(Smt::eq($marker, $reached));
        $this->returnMarkers[] = new NamedSymbol($marker->smt, 'return', Sort::bool(), $line);
    }

    /**
     * Condition under which the method returns normally.
     */
    public function anyReturn(): Term
    {
        return Smt::any(...$this->returnConditions);
    }

    /**
     * Marks the end of the parameter domains and `#[Requires]` clauses. The satisfiability
     * check of the assumptions only needs what comes before it: the body defines values
     * from the inputs and cannot make them unsatisfiable.
     */
    public function endAssumptions(): void
    {
        $this->assumptionBoundary = count($this->commands);
    }

    public function assumptionBoundary(): ?int
    {
        return $this->assumptionBoundary;
    }

    public function addObligation(Obligation $obligation): void
    {
        if ($obligation->goal->isTrue() || $obligation->pathCondition->isFalse()) {
            return;
        }

        $this->obligations[] = $this->loops === [] ? $obligation : $obligation->inIterations(array_map(
            static fn (array $frame): array => [$frame[0], $frame[1]],
            $this->loops,
        ));
    }

    /**
     * Starts translating iteration `$iteration` (0-based) of the `foreach` at `$line`, which is
     * unrolled `$bound` times. The obligations added until {@see self::leaveIteration()} are
     * stamped with it, and the loops inside it count towards the size of the unrolling.
     */
    public function enterIteration(int $line, int $iteration, int $bound): void
    {
        $this->loops[] = [$line, $iteration, $bound];
        ++$this->unrolledIterations;
    }

    public function leaveIteration(): void
    {
        array_pop($this->loops);
    }

    /**
     * The bounds of the loops being translated, outermost first.
     *
     * @return list<int>
     */
    public function enclosingBounds(): array
    {
        return array_map(static fn (array $frame): int => $frame[2], $this->loops);
    }

    /**
     * Notes that the `foreach` at `$line` is unrolled. A loop inside another is met once per
     * iteration of the outer one, and counts once, with its largest bound.
     */
    public function noteUnrolledLoop(int $line, LoopBound $loop): void
    {
        $this->loopDepths[$line] = max($this->loopDepths[$line] ?? 0, count($this->loops));
        $known = $this->unrolledLoops[$line] ?? null;
        if ($known === null || $known->iterations < $loop->iterations) {
            $this->unrolledLoops[$line] = $loop;
        }
    }

    /**
     * Notes the list that an outermost loop iterates, for a split of a check by its length. Among
     * several, the one with the largest bound is kept: it is the one that costs the most.
     *
     * @param string $length the SMT term of the length of the list
     */
    public function noteSplitLength(string $length, int $bound, string $subject): void
    {
        if ($this->splitLength === null || $this->splitLength[1] < $bound) {
            $this->splitLength = [$length, $bound, $subject];
        }
    }

    /**
     * @return array{string, int, string}|null the SMT term of the length, the bound of the unrolling and the list as written
     */
    public function splitLength(): ?array
    {
        return $this->splitLength;
    }

    /**
     * The loops the script unrolls, which make the length of each iterated list part of every check.
     *
     * @return array<int, LoopBound> by line
     */
    public function unrolledLoops(): array
    {
        return $this->unrolledLoops;
    }

    /**
     * Notes an operation that is not linear, which Z3 decides only in part: the product of two
     * terms that are not constants, or a division or a modulo by one. `$source` is the code as
     * written; the first one of each kind is kept to show in a message.
     *
     * @param 'product'|'division'|'modulo' $kind
     */
    public function noteNonLinear(string $kind, string $source): void
    {
        $this->nonLinearOperations[$kind] ??= $source;
        ++$this->nonLinearCount;
        if ($kind !== 'product' && $this->loops !== []) {
            ++$this->divisionsInLoops;
        }
    }

    /**
     * @return array<int, int> the nesting depth of each unrolled loop by line, 0 for an outermost one
     */
    public function loopDepths(): array
    {
        return $this->loopDepths;
    }

    /**
     * The copies of a loop body in the script: a loop of 5 iterations holding a loop of 5 has 30.
     */
    public function unrolledIterations(): int
    {
        return $this->unrolledIterations;
    }

    /**
     * The non-linear operations in the translated body, one per copy of a loop body that holds them.
     */
    public function nonLinearCount(): int
    {
        return $this->nonLinearCount;
    }

    /**
     * The divisions and modulos by a variable inside the copies of loop bodies, which are what makes
     * a proof over a list of symbolic length hard: each one sits behind the choice of the length.
     */
    public function divisionsInLoops(): int
    {
        return $this->divisionsInLoops;
    }

    /**
     * @return array<'product'|'division'|'modulo', string> the first operation of each kind, as written
     */
    public function nonLinearOperations(): array
    {
        return $this->nonLinearOperations;
    }

    /**
     * @return list<string>
     */
    public function commands(): array
    {
        return $this->commands;
    }

    /**
     * @return list<Sort>
     */
    public function datatypes(): array
    {
        return array_values($this->datatypes);
    }

    /**
     * @return list<array{EnumSort, 'value'|'name'}>
     */
    public function enumFunctions(): array
    {
        return array_values($this->enumFunctions);
    }

    /**
     * The terms to ask Z3 for after a satisfiable check: the observed symbols, then the
     * probes that read the lists inside them, since the model of a list is not
     * something to interpret (see {@see ProbePlanner}).
     *
     * @return list<string>
     */
    public function observedTerms(): array
    {
        $planner = new ProbePlanner();
        $terms = [];
        foreach ($this->observedSymbols() as $symbol) {
            $terms[] = $symbol->smtName;
            array_push($terms, ...$planner->probes($symbol->smtName, $symbol->sort));
        }

        return $terms;
    }

    /**
     * The terms among {@see self::observedTerms()} that are the length of a list, which a
     * counterexample can be asked to keep short.
     *
     * @return list<string>
     */
    public function lengthProbes(): array
    {
        return array_values(array_filter($this->observedTerms(), static fn (string $term): bool => ProbePlanner::isLength($term)));
    }

    /**
     * Symbols whose value is read back from a counterexample.
     *
     * @return list<NamedSymbol>
     */
    public function observedSymbols(): array
    {
        return [
            ...$this->inputs,
            ...($this->result === null ? [] : [$this->result]),
            ...$this->returnMarkers,
        ];
    }
}
