<?php

declare(strict_types=1);

namespace PhpstanContracts\Tests\Support;

use PhpstanContracts\Solver\Clock;

/**
 * A clock that only moves when a test says so, so that the budgets are tested without waiting.
 */
final class FakeClock implements Clock
{
    public function __construct(
        private int $nowMs = 10_000,
    ) {
    }

    public function nowMs(): int
    {
        return $this->nowMs;
    }

    public function advance(int $milliseconds): void
    {
        $this->nowMs += $milliseconds;
    }
}
