<?php

declare(strict_types=1);

namespace PhpstanContracts\Solver;

/**
 * The time source of the budgets, so that their logic is tested without waiting.
 */
interface Clock
{
    /**
     * Milliseconds on a monotonic clock: only differences between two readings mean something.
     */
    public function nowMs(): int;
}
