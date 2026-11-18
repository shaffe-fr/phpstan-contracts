<?php

declare(strict_types=1);

namespace PhpstanContracts\Examples\RealWorld;

use PhpstanContracts\Attribute\Ensures;
use PhpstanContracts\Attribute\Requires;

final class CancellationPolicy
{
    /**
     * Refund owed when a booking is cancelled, net of the non-refundable deposit.
     *
     * @param int<0, 100000000> $paidCents
     * @param int<0, 100000000> $depositCents
     * @param int<-365, 730> $daysBeforeArrival negative once the stay has started
     */
    #[Requires('$depositCents <= $paidCents')]
    #[Ensures('$result >= 0')]
    #[Ensures('$result <= $paidCents - $depositCents')]
    #[Ensures('implies($flexibleRate && $daysBeforeArrival >= 1, $result === $paidCents - $depositCents)')]
    #[Ensures('implies(!$flexibleRate && $daysBeforeArrival < 3, $result === 0)')]
    public static function refundCents(
        int $paidCents,
        int $depositCents,
        int $daysBeforeArrival,
        bool $flexibleRate,
    ): int {
        if ($flexibleRate && $daysBeforeArrival >= 1) {
            $refundable = $paidCents;
        } elseif ($daysBeforeArrival >= 30) {
            $refundable = $paidCents;
        } elseif ($daysBeforeArrival >= 14) {
            $refundable = intdiv($paidCents * 50, 100);
        } elseif ($daysBeforeArrival >= 3) {
            $refundable = intdiv($paidCents * 20, 100);
        } else {
            $refundable = 0;
        }

        return max(0, $refundable - $depositCents);
    }

    /**
     * Forgets the floor at zero: with a large deposit the "refund" becomes a charge.
     *
     * @param int<0, 100000000> $paidCents
     * @param int<0, 100000000> $depositCents
     * @param int<-365, 730> $daysBeforeArrival
     */
    #[Requires('$depositCents <= $paidCents')]
    #[Ensures('$result >= 0')]
    public static function refundCentsBroken(
        int $paidCents,
        int $depositCents,
        int $daysBeforeArrival,
        bool $flexibleRate,
    ): int {
        if ($flexibleRate && $daysBeforeArrival >= 1) {
            $refundable = $paidCents;
        } elseif ($daysBeforeArrival >= 30) {
            $refundable = $paidCents;
        } elseif ($daysBeforeArrival >= 14) {
            $refundable = intdiv($paidCents * 50, 100);
        } elseif ($daysBeforeArrival >= 3) {
            $refundable = intdiv($paidCents * 20, 100);
        } else {
            $refundable = 0;
        }

        return $refundable - $depositCents;
    }
}
