<?php

declare(strict_types=1);

namespace PhpstanContracts\Solver;

final readonly class MethodAnswer
{
    /**
     * @param list<SolverResult> $results one per check, in script order
     * @param array<int, list<int|string>> $undecidedLengths by check number, the cases of the split by length that were not decided
     * @param int $mainMs measured milliseconds of the main run
     * @param int $retryMs measured milliseconds of the single retries
     * @param int $splitMs measured milliseconds of the splits by length
     * @param bool $budgetExhausted whether the budget of the method was spent when the strategy ended
     */
    public function __construct(
        public array $results,
        public array $undecidedLengths = [],
        public int $mainMs = 0,
        public int $retryMs = 0,
        public int $splitMs = 0,
        public bool $budgetExhausted = false,
    ) {
    }

    /**
     * The checks that were not tried because the budget ran out first.
     */
    public function notTried(): int
    {
        return $this->count(UnknownReason::Budget) + $this->count(UnknownReason::RunBudget);
    }

    /**
     * The checks that an earlier run left undecided, so that Z3 was not asked.
     */
    public function remembered(): int
    {
        return $this->count(UnknownReason::Remembered);
    }

    private function count(UnknownReason $reason): int
    {
        return count(array_filter($this->results, static fn (SolverResult $result): bool => $result->reason === $reason));
    }
}
