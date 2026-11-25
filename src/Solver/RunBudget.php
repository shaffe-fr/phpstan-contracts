<?php

declare(strict_types=1);

namespace PhpstanContracts\Solver;

use InvalidArgumentException;

/**
 * The wall-clock time that the solver may spend in one PHPStan process, all methods together. A limit
 * of 0 is no limit. PHPStan workers are separate processes that do not share it.
 */
final class RunBudget
{
    private int $spentMs = 0;

    public function __construct(
        public readonly int $limitMs = 0,
    ) {
        if ($limitMs < 0) {
            throw new InvalidArgumentException(sprintf('The run budget cannot be negative, %d given.', $limitMs));
        }
    }

    public function spend(int $milliseconds): void
    {
        $this->spentMs += max(0, $milliseconds);
    }

    public function spentMs(): int
    {
        return $this->spentMs;
    }

    public function isExhausted(): bool
    {
        return $this->limitMs > 0 && $this->spentMs >= $this->limitMs;
    }
}
