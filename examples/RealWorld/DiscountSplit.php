<?php

declare(strict_types=1);

namespace PhpstanContracts\Examples\RealWorld;

use PhpstanContracts\Attribute\Ensures;
use PhpstanContracts\Attribute\Requires;

/**
 * Splits an order discount over the lines of the order. The parts must add up to
 * the discount, to the cent, whatever the number of lines.
 */
final class DiscountSplit
{
    /**
     * The same share for every line, and the cents that do not divide evenly go to
     * the first lines, one each.
     *
     * @param int<0, 10000000> $discountCents
     * @param non-empty-list<int<0, 100000000>> $lineCents
     * @return list<int>
     */
    #[Requires('count($lineCents) <= 20')]
    #[Ensures('count($result) === count($lineCents)')]
    #[Ensures('array_sum($result) === $discountCents')]
    public static function evenly(int $discountCents, array $lineCents): array
    {
        $share = intdiv($discountCents, count($lineCents));
        $remainder = $discountCents - $share * count($lineCents);
        $parts = [];
        foreach ($lineCents as $i => $cents) {
            $parts[] = $i < $remainder ? $share + 1 : $share;
        }

        return $parts;
    }

    /**
     * The remainder is forgotten: the parts add up to less than the discount
     * whenever it does not divide by the number of lines.
     *
     * @param int<0, 10000000> $discountCents
     * @param non-empty-list<int<0, 100000000>> $lineCents
     * @return list<int>
     */
    #[Requires('count($lineCents) <= 20')]
    #[Ensures('array_sum($result) === $discountCents')]
    public static function evenlyBroken(int $discountCents, array $lineCents): array
    {
        $share = intdiv($discountCents, count($lineCents));
        $parts = [];
        foreach ($lineCents as $cents) {
            $parts[] = $share;
        }

        return $parts;
    }

    /**
     * Each line takes what it can, never more than its own amount, until the
     * discount is used up. The caller guarantees that the lines can absorb it.
     *
     * @param int<0, 10000000> $discountCents
     * @param list<int<0, 100000000>> $lineCents
     * @return list<int>
     */
    #[Requires('count($lineCents) <= 10')]
    #[Requires('$discountCents <= array_sum($lineCents)')]
    #[Ensures('count($result) === count($lineCents)')]
    #[Ensures('array_sum($result) === $discountCents')]
    public static function cappedByLine(int $discountCents, array $lineCents): array
    {
        $parts = [];
        $left = $discountCents;
        foreach ($lineCents as $cents) {
            $part = min($cents, $left);
            $parts[] = $part;
            $left -= $part;
        }

        return $parts;
    }

    /**
     * Without the guarantee that the lines can absorb the discount, a part of it
     * is lost.
     *
     * @param int<0, 10000000> $discountCents
     * @param list<int<0, 100000000>> $lineCents
     * @return list<int>
     */
    #[Requires('count($lineCents) <= 10')]
    #[Ensures('array_sum($result) === $discountCents')]
    public static function cappedByLineBroken(int $discountCents, array $lineCents): array
    {
        $parts = [];
        $left = $discountCents;
        foreach ($lineCents as $cents) {
            $part = min($cents, $left);
            $parts[] = $part;
            $left -= $part;
        }

        return $parts;
    }
}
