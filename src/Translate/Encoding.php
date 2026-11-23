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
            if ($registered->enum?->className !== $sort->enum?->className || $registered->record?->className !== $sort->record?->className) {
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

        $this->obligations[] = $obligation;
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
