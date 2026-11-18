<?php

declare(strict_types=1);

namespace PhpstanContracts\Examples\RealWorld;

use PhpstanContracts\Attribute\Ensures;

final class Shipping
{
    /**
     * Shipping fee in cents: zone base fee, a surcharge per started 500 g above 1 kg,
     * an express supplement, free domestic standard shipping from 50.00, and a hard cap.
     *
     * @param int<0, 100000> $weightGrams
     * @param int<0, 100000000> $orderCents
     */
    #[Ensures('$result >= 0 && $result <= 5000')]
    #[Ensures('implies($zone === ShippingZone::Domestic && !$express && $orderCents >= 5000, $result === 0)')]
    #[Ensures('implies($express, $result >= 1480)')]
    public static function shippingCents(ShippingZone $zone, int $weightGrams, int $orderCents, bool $express): int
    {
        if ($zone === ShippingZone::Domestic && !$express && $orderCents >= 5000) {
            return 0;
        }

        $base = match ($zone) {
            ShippingZone::Domestic => 490,
            ShippingZone::Europe => 1290,
            ShippingZone::International => 2490,
        };

        $extraGrams = max(0, $weightGrams - 1000);
        $fee = $base + intdiv($extraGrams + 499, 500) * 150;

        if ($express) {
            $fee += 990;
        }

        return min($fee, 5000);
    }

    /**
     * The cap is applied before the express supplement, so it no longer caps anything.
     *
     * @param int<0, 100000> $weightGrams
     * @param int<0, 100000000> $orderCents
     */
    #[Ensures('$result <= 5000')]
    public static function shippingCentsBroken(ShippingZone $zone, int $weightGrams, int $orderCents, bool $express): int
    {
        $base = match ($zone) {
            ShippingZone::Domestic => 490,
            ShippingZone::Europe => 1290,
            ShippingZone::International => 2490,
        };

        $extraGrams = max(0, $weightGrams - 1000);
        $fee = min($base + intdiv($extraGrams + 499, 500) * 150, 5000);

        if ($express) {
            $fee += 990;
        }

        return $fee;
    }
}
