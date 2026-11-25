<?php

declare(strict_types=1);

namespace PhpstanContracts\Solver;

/**
 * The wall-clock time one method may spend with the solver, from its creation: the main run, the
 * single retries and the split by length. A budget of 0 is no limit.
 */
final class MethodBudget
{
    private readonly ?int $deadlineMs;

    public function __construct(
        private readonly Clock $clock,
        int $totalMs,
    ) {
        $this->deadlineMs = $totalMs > 0 ? $clock->nowMs() + $totalMs : null;
    }

    /**
     * The reading of the clock at which the budget is spent, null when there is no limit.
     */
    public function deadlineMs(): ?int
    {
        return $this->deadlineMs;
    }

    /**
     * @phpstan-impure the answer changes with the clock
     */
    public function isExhausted(): bool
    {
        return $this->deadlineMs !== null && $this->clock->nowMs() >= $this->deadlineMs;
    }
}
