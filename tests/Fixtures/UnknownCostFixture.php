<?php

declare(strict_types=1);

namespace PhpstanContracts\Tests\Fixtures;

use PhpstanContracts\Attribute\Ensures;
use PhpstanContracts\Attribute\Requires;

/**
 * Checks that Z3 cannot decide at the short timeout of the test, with a loop and non-linear
 * arithmetic that the message names. The bound is large enough that no timeout of a CI runner
 * decides the sum, not even with the proof split by the length of the list: from 6 lines on, Z3 does
 * not decide the longest cases in minutes.
 */
final class UnknownCostFixture
{
    /**
     * A proportional split whose remainder is given back one unit at a time.
     *
     * @param int<0, 10000000> $totalDiscount
     * @param non-empty-list<int<0, 10000000>> $lineTotals
     * @return list<int>
     */
    #[Requires('count($lineTotals) <= 8')]
    #[Requires('$totalDiscount <= array_sum($lineTotals)')]
    #[Requires('array_sum($lineTotals) >= 1')]
    #[Ensures('array_sum($result) === $totalDiscount')]
    public static function proportionalSplit(int $totalDiscount, array $lineTotals): array
    {
        $subtotal = array_sum($lineTotals);
        $parts = [];
        $distributed = 0;
        foreach ($lineTotals as $lineTotal) {
            $part = intdiv($totalDiscount * $lineTotal, $subtotal);
            $parts[] = $part;
            $distributed += $part;
        }

        $residual = $totalDiscount - $distributed;
        $out = [];
        foreach ($parts as $i => $part) {
            if ($residual > 0 && $part < $lineTotals[$i]) {
                $part += 1;
                $residual -= 1;
            }
            $out[] = $part;
        }

        return $out;
    }
}
