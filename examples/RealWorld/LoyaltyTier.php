<?php

declare(strict_types=1);

namespace PhpstanContracts\Examples\RealWorld;

use PhpstanContracts\Attribute\Ensures;

/**
 * The loyalty tiers of a shop. The pricing rule that uses them is in another file,
 * LoyaltyPricing.php: its proof reads the discount from here.
 */
enum LoyaltyTier
{
    case Bronze;
    case Silver;
    case Gold;

    /**
     * The discount of the tier in basis points (100 is 1%), never more than 20%.
     */
    #[Ensures('$result >= 0 && $result <= 2000')]
    public function discountBasisPoints(): int
    {
        return match ($this) {
            self::Bronze => 0,
            self::Silver => 500,
            self::Gold => 1500,
        };
    }

    /**
     * A typo while changing the Gold discount: 105.00% instead of 15.00%.
     */
    #[Ensures('$result >= 0 && $result <= 2000')]
    public function discountBasisPointsBroken(): int
    {
        return match ($this) {
            self::Bronze => 0,
            self::Silver => 500,
            self::Gold => 10500,
        };
    }
}
