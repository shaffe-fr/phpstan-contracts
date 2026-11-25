<?php

declare(strict_types=1);

namespace PhpstanContracts\Tests\Fixtures;

use PhpstanContracts\Attribute\Ensures;
use PhpstanContracts\Attribute\Requires;

/**
 * The structure of the proportional split of a discount over 3, 5 and 8 lines, and two methods whose
 * structure is not risky. Only their shape matters: the tests that use them do not read the verdicts.
 */
final class CostFixture
{
    /**
     * @param int<0, 10000000> $totalDiscount
     * @param non-empty-list<int<0, 10000000>> $lineTotals
     * @return list<int>
     */
    #[Requires('count($lineTotals) <= 3')]
    #[Requires('array_sum($lineTotals) >= 1')]
    #[Ensures('count($result) === count($lineTotals)')]
    public static function split3(int $totalDiscount, array $lineTotals): array
    {
        $subtotal = array_sum($lineTotals);
        $parts = [];
        foreach ($lineTotals as $lineTotal) {
            $parts[] = intdiv($totalDiscount * $lineTotal, $subtotal);
        }

        return $parts;
    }

    /**
     * @param int<0, 10000000> $totalDiscount
     * @param non-empty-list<int<0, 10000000>> $lineTotals
     * @return list<int>
     */
    #[Requires('count($lineTotals) <= 5')]
    #[Requires('array_sum($lineTotals) >= 1')]
    #[Ensures('count($result) === count($lineTotals)')]
    public static function split5(int $totalDiscount, array $lineTotals): array
    {
        $subtotal = array_sum($lineTotals);
        $parts = [];
        foreach ($lineTotals as $lineTotal) {
            $parts[] = intdiv($totalDiscount * $lineTotal, $subtotal);
        }

        return $parts;
    }

    /**
     * @param int<0, 10000000> $totalDiscount
     * @param non-empty-list<int<0, 10000000>> $lineTotals
     * @return list<int>
     */
    #[Requires('count($lineTotals) <= 8')]
    #[Requires('array_sum($lineTotals) >= 1')]
    #[Ensures('count($result) === count($lineTotals)')]
    public static function split8(int $totalDiscount, array $lineTotals): array
    {
        $subtotal = array_sum($lineTotals);
        $parts = [];
        foreach ($lineTotals as $lineTotal) {
            $parts[] = intdiv($totalDiscount * $lineTotal, $subtotal);
        }

        return $parts;
    }

    /**
     * A product of two variables in each of 10 iterations: easy for Z3, so not flagged.
     *
     * @param list<int<0, 1000>> $quantities
     * @param list<int<0, 1000>> $prices
     */
    #[Requires('count($quantities) <= 10')]
    #[Requires('count($prices) === count($quantities)')]
    #[Ensures('$result >= 0')]
    public static function productsOnly(array $quantities, array $prices): int
    {
        $total = 0;
        foreach ($quantities as $index => $quantity) {
            $total += $quantity * $prices[$index];
        }

        return $total;
    }

    /**
     * @param int<1, 1000000> $page
     * @param int<1, 1000> $perPage
     */
    #[Ensures('$result % $perPage === 0')]
    public static function withoutLoop(int $page, int $perPage): int
    {
        return ($page - 1) * $perPage;
    }
}
