<?php

declare(strict_types=1);

namespace PhpstanContracts\Examples;

use PhpstanContracts\Attribute\Ensures;
use PhpstanContracts\Attribute\Requires;

final class Pricing
{
    /**
     * Proven: long stays get 10 extra points of discount, never above 100%.
     *
     * @param int<0, 1000000> $unitPriceCents
     * @param int<0, 100> $discountPercent
     */
    #[Requires('$nights >= 1 && $nights <= 365')]
    #[Ensures('$result >= 0')]
    #[Ensures('$result <= $unitPriceCents * $nights')]
    public static function stayTotal(int $unitPriceCents, int $nights, int $discountPercent): int
    {
        $gross = $unitPriceCents * $nights;

        if ($nights >= 7) {
            $discountPercent = min(100, $discountPercent + 10);
        }

        return intdiv($gross * (100 - $discountPercent), 100);
    }

    /**
     * Proven: the match covers every case and agrees with the enum backing value.
     */
    #[Ensures('$result === $tier->value')]
    #[Ensures('implies($tier === Tier::Gold, $result === 15)')]
    public static function loyaltyBonus(Tier $tier): int
    {
        return match ($tier) {
            Tier::Standard => 0,
            Tier::Silver => 5,
            Tier::Gold => 15,
        };
    }

    /**
     * Refuted: discount plus loyalty bonus can exceed 100%, which makes the total negative.
     *
     * @param int<0, 1000000> $unitPriceCents
     * @param int<0, 365> $nights
     * @param int<0, 100> $discountPercent
     */
    #[Ensures('$result >= 0')]
    public static function totalWithTier(int $unitPriceCents, int $nights, int $discountPercent, Tier $tier): int
    {
        if ($nights === 0) {
            return 0;
        }

        $percent = $discountPercent + match ($tier) {
            Tier::Standard => 0,
            Tier::Silver => 5,
            Tier::Gold => 15,
        };
        $gross = $unitPriceCents * $nights;

        return $gross - intdiv($gross * $percent, 100);
    }

    /**
     * Proven: same computation with the combined discount capped at 100%.
     *
     * @param int<0, 1000000> $unitPriceCents
     * @param int<0, 365> $nights
     * @param int<0, 100> $discountPercent
     */
    #[Ensures('$result >= 0')]
    #[Ensures('$result <= $unitPriceCents * $nights')]
    public static function totalWithTierCapped(int $unitPriceCents, int $nights, int $discountPercent, Tier $tier): int
    {
        if ($nights === 0) {
            return 0;
        }

        $percent = min(100, $discountPercent + $tier->value);
        $gross = $unitPriceCents * $nights;

        return $gross - intdiv($gross * $percent, 100);
    }
}
