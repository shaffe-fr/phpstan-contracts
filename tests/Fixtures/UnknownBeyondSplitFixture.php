<?php

declare(strict_types=1);

namespace PhpstanContracts\Tests\Fixtures;

use PhpstanContracts\Attribute\Ensures;
use PhpstanContracts\Attribute\Requires;

/**
 * A check that Z3 cannot decide at the short timeout of the test, in a method whose list may have
 * 11 lengths: with the case for the lengths outside the bound that is 13 cases, more than a split
 * may have, so the check is not split.
 */
final class UnknownBeyondSplitFixture
{
    /**
     * @param int<0, 100> $totalDiscount
     * @param non-empty-list<int<0, 100>> $lineTotals
     * @return list<int>
     */
    #[Requires('count($lineTotals) <= 11')]
    #[Requires('$totalDiscount <= array_sum($lineTotals)')]
    #[Requires('array_sum($lineTotals) >= 1')]
    #[Ensures('$totalDiscount - array_sum($result) < count($lineTotals)')]
    public static function proportionalSplit(int $totalDiscount, array $lineTotals): array
    {
        $subtotal = array_sum($lineTotals);
        $parts = [];
        foreach ($lineTotals as $lineTotal) {
            $parts[] = intdiv($totalDiscount * $lineTotal, $subtotal);
        }

        return $parts;
    }
}
