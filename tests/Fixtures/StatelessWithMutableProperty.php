<?php

declare(strict_types=1);

namespace PhpstanContracts\Tests\Fixtures;

use PhpstanContracts\Attribute\Ensures;

final class StatelessWithMutableProperty
{
    private int $count = 0;

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
