<?php

declare(strict_types=1);

namespace PhpstanContracts\Examples\RealWorld;

use PhpstanContracts\Attribute\Ensures;

/**
 * A price rule that relies on a discount defined in another file. The proof translates
 * the body of LoyaltyTier::discountBasisPoints(), and is redone when that file changes.
 */
final class LoyaltyPricing
{
    /**
     * The price after the loyalty discount: never negative, never above the original price.
     *
     * @param int<0, 1000000000> $cents
     */
    #[Ensures('$result >= 0 && $result <= $cents')]
    public static function priceAfterLoyalty(int $cents, LoyaltyTier $tier): int
    {
        return intdiv($cents * (10000 - $tier->discountBasisPoints()), 10000);
    }

    /**
     * The same rule over the tier method with the typo: the bug is in LoyaltyTier.php,
     * and this rule is the one that breaks.
     *
     * @param int<0, 1000000000> $cents
     */
    #[Ensures('$result >= 0 && $result <= $cents')]
    public static function priceAfterLoyaltyBroken(int $cents, LoyaltyTier $tier): int
    {
        return intdiv($cents * (10000 - $tier->discountBasisPointsBroken()), 10000);
    }
}
