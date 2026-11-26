<?php

declare(strict_types=1);

namespace PhpstanContracts\Examples\RealWorld;

use PhpstanContracts\Attribute\Ensures;
use PhpstanContracts\Attribute\Requires;

/**
 * The refund rule builds a Refund declared in another file. The proof reads its constructor
 * there and follows it: if the constructor gains logic, the rule is rejected instead of proven.
 */
final class RefundPolicy
{
    /**
     * Full refund up to a week before the stay, half up to the day before, nothing after.
     * Never more than what was paid.
     */
    #[Requires('$paidCents >= 0 && $paidCents <= 100000000 && $daysBeforeStay >= 0 && $daysBeforeStay <= 365')]
    #[Ensures('$result->paidCents === $paidCents')]
    #[Ensures('$result->refundedCents <= $result->paidCents')]
    public static function refund(int $paidCents, int $daysBeforeStay): Refund
    {
        $percent = match (true) {
            $daysBeforeStay >= 7 => 100,
            $daysBeforeStay >= 1 => 50,
            default => 0,
        };

        return new Refund($paidCents, intdiv($paidCents * $percent, 100));
    }

    /**
     * The full refund was written as 110, to include the booking fee: it refunds more than was paid.
     */
    #[Requires('$paidCents >= 0 && $paidCents <= 100000000 && $daysBeforeStay >= 0 && $daysBeforeStay <= 365')]
    #[Ensures('$result->refundedCents <= $result->paidCents')]
    public static function refundBroken(int $paidCents, int $daysBeforeStay): Refund
    {
        $percent = match (true) {
            $daysBeforeStay >= 7 => 110,
            $daysBeforeStay >= 1 => 50,
            default => 0,
        };

        return new Refund($paidCents, intdiv($paidCents * $percent, 100));
    }
}
