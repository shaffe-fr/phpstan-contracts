<?php

declare(strict_types=1);

namespace PhpstanContracts\Examples\RealWorld;

use PhpstanContracts\Attribute\Ensures;
use PhpstanContracts\Attribute\Requires;

/**
 * Amounts are integer cents, VAT rates are basis points (2000 = 20%).
 */
final class Invoice
{
    /**
     * VAT rounded half up to the nearest cent, for invoices.
     *
     * @param int<0, 1000000000> $netCents
     * @param int<0, 3000> $rateBasisPoints
     */
    #[Ensures('$result >= 0')]
    #[Ensures('abs($result * 10000 - $netCents * $rateBasisPoints) <= 5000')]
    #[Ensures('implies($rateBasisPoints === 0, $result === 0)')]
    public static function vatCents(int $netCents, int $rateBasisPoints): int
    {
        return intdiv($netCents * $rateBasisPoints + 5000, 10000);
    }

    /**
     * Same formula, extended to credit notes (negative net amounts).
     * intdiv() truncates toward zero, so the half-up rounding breaks below zero.
     *
     * @param int<-1000000000, 1000000000> $netCents
     * @param int<0, 3000> $rateBasisPoints
     */
    #[Ensures('abs($result * 10000 - $netCents * $rateBasisPoints) <= 5000')]
    public static function vatCentsNaive(int $netCents, int $rateBasisPoints): int
    {
        return intdiv($netCents * $rateBasisPoints + 5000, 10000);
    }

    /**
     * Rounds the magnitude, then restores the sign: a credit note mirrors its invoice.
     *
     * @param int<-1000000000, 1000000000> $netCents
     * @param int<0, 3000> $rateBasisPoints
     */
    #[Ensures('abs($result * 10000 - $netCents * $rateBasisPoints) <= 5000')]
    #[Ensures('implies($netCents < 0, $result <= 0)')]
    #[Ensures('implies($netCents >= 0, $result >= 0)')]
    public static function vatCentsSigned(int $netCents, int $rateBasisPoints): int
    {
        $magnitude = intdiv(abs($netCents) * $rateBasisPoints + 5000, 10000);

        return $netCents < 0 ? -$magnitude : $magnitude;
    }

    /**
     * Splits a total into equal installments; the first ones absorb the remainder.
     *
     * @param int<0, 100000000> $totalCents
     * @param int<1, 120> $count
     */
    #[Requires('$index >= 0 && $index < $count')]
    #[Ensures('$result >= intdiv($totalCents, $count)')]
    #[Ensures('$result <= intdiv($totalCents, $count) + 1')]
    #[Ensures('implies($index < $totalCents % $count, $result === intdiv($totalCents, $count) + 1)')]
    public static function installmentCents(int $totalCents, int $count, int $index): int
    {
        $base = intdiv($totalCents, $count);
        $remainder = $totalCents % $count;

        return $index < $remainder ? $base + 1 : $base;
    }

    /**
     * Closed form of "installment 0 + installment 1 + ...": no cent is lost or created.
     *
     * @param int<0, 100000000> $totalCents
     * @param int<1, 120> $count
     */
    #[Ensures('$result === $totalCents')]
    public static function sumOfInstallments(int $totalCents, int $count): int
    {
        $base = intdiv($totalCents, $count);
        $remainder = $totalCents % $count;

        return $remainder * ($base + 1) + ($count - $remainder) * $base;
    }

    /**
     * Rounding every installment down silently drops the remainder.
     *
     * @param int<0, 100000000> $totalCents
     * @param int<1, 120> $count
     */
    #[Ensures('$result === $totalCents')]
    public static function sumOfInstallmentsNaive(int $totalCents, int $count): int
    {
        return $count * intdiv($totalCents, $count);
    }
}
