<?php

declare(strict_types=1);

namespace PhpstanContracts\Examples\RealWorld;

use PhpstanContracts\Attribute\Ensures;

/**
 * Enum logic written as `match ($this)`: `$this` is one of the cases.
 */
enum StockStatus: int
{
    case InStock = 1;
    case OutOfStock = 2;
    case TemporarilyUnavailable = 5;

    /**
     * Only a product in stock can be bought.
     */
    #[Ensures('implies($this === StockStatus::InStock, $result)')]
    #[Ensures('implies($this === StockStatus::TemporarilyUnavailable, !$result)')]
    public function isPurchasable(): bool
    {
        return match ($this) {
            self::InStock => true,
            self::OutOfStock, self::TemporarilyUnavailable => false,
        };
    }

    /**
     * A temporary shortage was treated as "still for sale".
     */
    #[Ensures('implies($this === StockStatus::TemporarilyUnavailable, !$result)')]
    public function isPurchasableBroken(): bool
    {
        return match ($this) {
            self::InStock, self::TemporarilyUnavailable => true,
            self::OutOfStock => false,
        };
    }

    /**
     * The code sent to the ERP: the backing value, except for a shortage, which the ERP calls out of stock.
     */
    #[Ensures('$result === 1 || $result === 2')]
    #[Ensures('implies($this !== self::TemporarilyUnavailable, $result === $this->value)')]
    public function erpCode(): int
    {
        return $this === self::TemporarilyUnavailable ? self::OutOfStock->value : $this->value;
    }
}
