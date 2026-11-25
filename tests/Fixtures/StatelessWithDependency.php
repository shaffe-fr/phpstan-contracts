<?php

declare(strict_types=1);

namespace PhpstanContracts\Tests\Fixtures;

use PhpstanContracts\Attribute\Ensures;

/**
 * A promoted constructor dependency is state, even when private and readonly.
 */
final class StatelessWithDependency
{
    public function __construct(private readonly int $rate)
    {
    }

    /**
     * @param int<0, 1000> $amount
     */
    #[Ensures('$result >= 0')]
    public function callsAHelper(int $amount): int
    {
        return $this->helper($amount);
    }

    private function helper(int $amount): int
    {
        return $amount;
    }
}
