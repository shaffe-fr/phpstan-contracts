<?php

declare(strict_types=1);

namespace PhpstanContracts\Tests\Fixtures;

use PhpstanContracts\Attribute\Ensures;

/**
 * Every method here is refuted, with `$this` in the counterexample. The replay
 * test calls each one on the reported case.
 */
enum ReplayStatus: int
{
    case Active = 1;
    case Paused = 2;
    case Closed = 3;

    #[Ensures('implies($this === self::Closed, !$result)')]
    public function acceptsOrders(): bool
    {
        return $this !== self::Paused;
    }

    /** @param int<0, 10> $n */
    #[Ensures('$result < 10')]
    public function scaled(int $n): int
    {
        return $this->value * $n;
    }
}
