<?php

declare(strict_types=1);

namespace PhpstanContracts\Translate;

use PhpstanContracts\Smt\Term;

/**
 * A safety condition that must hold wherever execution reaches `$pathCondition`.
 */
final readonly class Obligation
{
    public function __construct(
        public ObligationKind $kind,
        public int $line,
        public string $expression,
        public Term $pathCondition,
        public Term $goal,
        /**
         * The inlined calls the obligation comes from, outermost first, such as
         * `['Role::canManage()', 'Role::level()']`. For a precondition, the last one is
         * the method whose `#[Requires]` it is.
         *
         * @var list<string>
         */
        public array $calls = [],
        /**
         * For an unexpected throw, the condition under which the `#[Throws]` declarations
         * allow it, in words, or null when none of them declares its class.
         */
        public ?string $allowedWhere = null,
        /**
         * For a read of a list element, the condition that makes the key exist, as code the
         * author can paste, such as `$index >= 0 && $index < count($amounts)`.
         */
        public ?string $guard = null,
        /** For a `loopBound`, the bound the proof unrolls to. */
        public ?LoopBound $loop = null,
        /**
         * The loop iterations the obligation was created in, outermost first, as the line of
         * the `foreach` and the 0-based iteration. The copies of one obligation made by the
         * iterations of a loop are reported once.
         *
         * @var list<array{int, int}>
         */
        public array $iterations = [],
    ) {
    }

    /**
     * @param list<array{int, int}> $iterations
     */
    public function inIterations(array $iterations): self
    {
        return new self(
            $this->kind,
            $this->line,
            $this->expression,
            $this->pathCondition,
            $this->goal,
            $this->calls,
            $this->allowedWhere,
            $this->guard,
            $this->loop,
            $iterations,
        );
    }

    /**
     * What the copies of one obligation have in common.
     */
    public function groupKey(): string
    {
        return implode('|', [$this->kind->value, $this->line, $this->expression, implode(',', $this->calls)]);
    }
}
