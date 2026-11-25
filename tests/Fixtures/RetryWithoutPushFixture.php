<?php

declare(strict_types=1);

namespace PhpstanContracts\Tests\Fixtures;

use PhpstanContracts\Attribute\Ensures;
use PhpstanContracts\Attribute\Requires;

/**
 * A check that the main script leaves `unknown` and that Z3 decides once it is asked alone, in a
 * script that never pushes a scope: the incremental solver does not decide it, the other one does.
 */
final class RetryWithoutPushFixture
{
    /**
     * Each part is at most its share, so what the parts leave out of the discount is below one unit
     * per line: the fact that makes one remainder pass enough.
     *
     * @param int<0, 10000000> $totalDiscount
     * @param non-empty-list<int<0, 10000000>> $lineTotals
     * @return list<int>
     */
    #[Requires('count($lineTotals) <= 4')]
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
