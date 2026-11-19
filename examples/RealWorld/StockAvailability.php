<?php

declare(strict_types=1);

namespace PhpstanContracts\Examples\RealWorld;

use PhpstanContracts\Attribute\Ensures;

/**
 * An enum that returns its own cases through `self::`, in the body and in the contract.
 */
enum StockAvailability: string
{
    case Immediate = 'immediate';
    case EndOfLife = 'end_of_life';

    /**
     * A product is only available immediately when it is in stock.
     */
    #[Ensures('implies($result === StockAvailability::Immediate, $stockQuantity > 0)')]
    #[Ensures('implies($stockQuantity <= 0, $result === self::EndOfLife)')]
    public static function resolve(int $stockQuantity): self
    {
        if ($stockQuantity > 0) {
            return self::Immediate;
        }

        return self::EndOfLife;
    }

    /**
     * The comparison was written `>=`, so a product with no stock left is offered immediately.
     */
    #[Ensures('implies($result === StockAvailability::Immediate, $stockQuantity > 0)')]
    public static function resolveBroken(int $stockQuantity): self
    {
        if ($stockQuantity >= 0) {
            return self::Immediate;
        }

        return self::EndOfLife;
    }
}
