<?php

declare(strict_types=1);

namespace PhpstanContracts\Translate;

use PhpstanContracts\Smt\Term;

/**
 * Symbolic state during execution: the current SSA term of each local variable
 * and the condition under which control is still flowing (no `return` taken yet).
 *
 * `narrowed` holds views learned from null checks on this path: `x` maps to the
 * unwrapped value of a nullable variable, `dto->field` to a narrowed field. The
 * variables themselves keep their declared sort, so a merge can restore it.
 */
final readonly class State
{
    /**
     * @param array<string, Term> $variables
     * @param array<string, Term> $narrowed
     */
    public function __construct(
        public array $variables,
        public Term $reached,
        public array $narrowed = [],
    ) {
    }

    /**
     * What expressions see: the variables, overridden by their narrowed views.
     *
     * @return array<string, Term>
     */
    public function view(): array
    {
        return [...$this->variables, ...$this->narrowed];
    }

    public function withReached(Term $reached): self
    {
        return new self($this->variables, $reached, $this->narrowed);
    }

    /**
     * Assigning a variable forgets what was known about it and about its fields.
     *
     * @param Term|null $narrowedValue the non-null value, when a nullable variable receives one
     */
    public function withVariable(string $name, Term $value, ?Term $narrowedValue = null): self
    {
        $narrowed = array_filter(
            $this->narrowed,
            static fn (string $key): bool => $key !== $name && !str_starts_with($key, $name . '->'),
            ARRAY_FILTER_USE_KEY,
        );
        if ($narrowedValue !== null) {
            $narrowed[$name] = $narrowedValue;
        }

        return new self([...$this->variables, $name => $value], $this->reached, $narrowed);
    }

    /**
     * @param array<string, Term> $view a narrowed copy of {@see view()}
     */
    public function withView(array $view): self
    {
        $current = $this->view();
        $narrowed = $this->narrowed;
        foreach ($view as $key => $term) {
            if (!isset($current[$key]) || $current[$key]->smt !== $term->smt) {
                $narrowed[$key] = $term;
            }
        }

        return new self($this->variables, $this->reached, $narrowed);
    }
}
