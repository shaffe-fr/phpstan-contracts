<?php

declare(strict_types=1);

namespace PhpstanContracts\Tests\Fixtures;

use PhpstanContracts\Attribute\Ensures;
use PhpstanContracts\Attribute\Requires;

/**
 * Run with `contracts.loopUnroll: 3` and `contracts.maxLoopUnroll: 8`.
 */
final class ForeachConfigurationFixture
{
    /**
     * @param list<int<0, 1000>> $amounts
     */
    #[Requires('count($amounts) <= 3')]
    #[Ensures('$result <= 3000')]
    public static function fitsTheDefault(array $amounts): int
    {
        $total = 0;
        foreach ($amounts as $amount) {
            $total += $amount;
        }

        return $total;
    }

    /**
     * @param list<int<0, 1000>> $amounts
     */
    #[Ensures('$result >= 0')]
    public static function exceedsTheDefault(array $amounts): int
    {
        $total = 0;
        foreach ($amounts as $amount) {
            $total += $amount;
        }

        return $total;
    }

    /**
     * @param list<int<0, 1000>> $amounts
     */
    #[Requires('count($amounts) <= 8')]
    #[Ensures('$result <= 8000')]
    public static function fitsTheBudget(array $amounts): int
    {
        $total = 0;
        foreach ($amounts as $amount) {
            $total += $amount;
        }

        return $total;
    }

    /**
     * @param list<int<0, 1000>> $amounts
     */
    #[Requires('count($amounts) <= 9')]
    #[Ensures('$result <= 9000')]
    public static function exceedsTheBudget(array $amounts): int
    {
        $total = 0;
        foreach ($amounts as $amount) {
            $total += $amount;
        }

        return $total;
    }
}
