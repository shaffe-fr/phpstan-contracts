<?php

declare(strict_types=1);

namespace PhpstanContracts\Tests\Fixtures;

use PhpstanContracts\Attribute\Ensures;
use PhpstanContracts\Attribute\Requires;

/**
 * A proof that neither the main script nor its retry decides, however long they run, and that the
 * split by the length of the list does: for each of the lengths it is a few small products.
 */
final class SplitProvenFixture
{
    /**
     * Each line takes the floor of its share, so what is left is less than one unit per line.
     *
     * @param int<0, 10000000> $totalDiscount
     * @param non-empty-list<int<0, 10000000>> $lineTotals
     * @return list<int>
     */
    #[Requires('count($lineTotals) <= 5')]
    #[Requires('$totalDiscount <= array_sum($lineTotals)')]
    #[Requires('array_sum($lineTotals) >= 1')]
    #[Ensures('$totalDiscount - array_sum($result) < count($lineTotals)')]
    public static function leavesLessThanOneUnitPerLine(int $totalDiscount, array $lineTotals): array
    {
        $subtotal = array_sum($lineTotals);
        $parts = [];
        foreach ($lineTotals as $lineTotal) {
            $parts[] = intdiv($totalDiscount * $lineTotal, $subtotal);
        }

        return $parts;
    }
}
