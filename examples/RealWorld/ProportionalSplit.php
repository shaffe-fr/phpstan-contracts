<?php
declare(strict_types=1);
namespace PhpstanContracts\Examples\RealWorld;
use PhpstanContracts\Attribute\Ensures;
use PhpstanContracts\Attribute\Requires;
/**
 * Splits an order discount over the lines in proportion to their amounts. Every
 * line gets the rounded-down share of its own amount, so a few cents are left over,
 * and the parts must still add up to the discount, to the cent.
 */
final class ProportionalSplit
{
    /**
     * The cents left by the rounding are given back one at a time, to the lines that
     * can still take one.
     *
     * @param int<0, 10000000> $totalDiscount
     * @param non-empty-list<int<0, 10000000>> $lineTotals
     * @return list<int>
     */
    #[Requires('count($lineTotals) <= 3')]
    #[Requires('$totalDiscount <= array_sum($lineTotals)')]
    #[Requires('array_sum($lineTotals) >= 1')]
    #[Ensures('count($result) === count($lineTotals)')]
    #[Ensures('array_sum($result) === $totalDiscount')]
    #[Ensures('$result[0] <= $lineTotals[0]')]
    #[Ensures('$result[0] >= 0')]
    public static function proportional(int $totalDiscount, array $lineTotals): array
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

    /**
     * Without the second pass the cents left by the rounding are lost.
     *
     * @param int<0, 10000000> $totalDiscount
     * @param non-empty-list<int<0, 10000000>> $lineTotals
     * @return list<int>
     */
    #[Requires('count($lineTotals) <= 3')]
    #[Requires('$totalDiscount <= array_sum($lineTotals)')]
    #[Requires('array_sum($lineTotals) >= 1')]
    #[Ensures('count($result) === count($lineTotals)')]
    #[Ensures('array_sum($result) === $totalDiscount')]
    public static function proportionalBroken(int $totalDiscount, array $lineTotals): array
    {
        $subtotal = array_sum($lineTotals);
        $parts = [];
        foreach ($lineTotals as $lineTotal) {
            $parts[] = intdiv($totalDiscount * $lineTotal, $subtotal);
        }
        return $parts;
    }
}
