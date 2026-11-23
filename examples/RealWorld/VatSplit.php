<?php

declare(strict_types=1);

namespace PhpstanContracts\Examples\RealWorld;

use PhpstanContracts\Attribute\Ensures;
use PhpstanContracts\Attribute\Requires;

/**
 * Splitting a gross price into net and VAT, in cents, with an array shape.
 */
final class VatSplit
{
    /**
     * Splits a gross amount into its net part and its VAT. The VAT is what is left
     * once the net part is known, so the two parts always add up to the gross amount.
     *
     * @param int<0, 100000000> $grossCents
     * @param int<0, 10000> $rateBasisPoints
     * @return array{net: int, vat: int}
     */
    #[Ensures('$result["net"] + $result["vat"] === $grossCents')]
    #[Ensures('$result["vat"] >= 0')]
    public static function split(int $grossCents, int $rateBasisPoints): array
    {
        $net = intdiv($grossCents * 10000, 10000 + $rateBasisPoints);

        return ['net' => $net, 'vat' => $grossCents - $net];
    }

    /**
     * Computes both parts with a rounded-down division: each one loses the
     * remainder of its own division, so a cent can vanish.
     *
     * @param int<0, 100000000> $grossCents
     * @param int<0, 10000> $rateBasisPoints
     * @return array{net: int, vat: int}
     */
    #[Ensures('$result["net"] + $result["vat"] === $grossCents')]
    public static function splitBroken(int $grossCents, int $rateBasisPoints): array
    {
        $net = intdiv($grossCents * 10000, 10000 + $rateBasisPoints);
        $vat = intdiv($grossCents * $rateBasisPoints, 10000 + $rateBasisPoints);

        return ['net' => $net, 'vat' => $vat];
    }

    /**
     * Share of the VAT in the gross amount, as a percentage rounded down.
     *
     * @param array{net: int<0, 100000000>, vat: int<0, 100000000>} $split
     */
    #[Requires('$split["net"] + $split["vat"] > 0')]
    #[Ensures('$result >= 0 && $result <= 100')]
    public static function vatShare(array $split): int
    {
        return intdiv($split['vat'] * 100, $split['net'] + $split['vat']);
    }
}
