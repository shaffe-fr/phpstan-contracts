<?php

declare(strict_types=1);

namespace PhpstanContracts\Examples;

use PhpstanContracts\Attribute\Ensures;

/**
 * Not final: a subclass can override MAX_ITEMS, so `static::MAX_ITEMS` has no single value.
 */
class CartLimits
{
    public const int MAX_ITEMS = 50;

    final public const int MAX_QUANTITY = 99;

    /** Rejected: `static::` is late bound and MAX_ITEMS can be overridden. */
    #[Ensures('$result <= 50')]
    public static function itemsLateBound(int $items): int
    {
        return min($items, static::MAX_ITEMS);
    }

    /** Proven: `self::` always means the constant of this class. */
    #[Ensures('$result <= 50')]
    public static function items(int $items): int
    {
        return min($items, self::MAX_ITEMS);
    }

    /** Proven: a final constant cannot be overridden, so `static::` is safe. */
    #[Ensures('$result <= self::MAX_QUANTITY')]
    public static function quantity(int $quantity): int
    {
        return min($quantity, static::MAX_QUANTITY);
    }
}
