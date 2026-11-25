<?php

declare(strict_types=1);

namespace PhpstanContracts\Solver;

final readonly class MethodAnswer
{
    /**
     * @param list<SolverResult> $results one per check, in script order
     * @param array<int, list<int|string>> $undecidedLengths by check number, the cases of the split by length that were not decided
     */
    public function __construct(
        public array $results,
        public array $undecidedLengths = [],
    ) {
    }
}
