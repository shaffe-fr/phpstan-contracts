<?php

declare(strict_types=1);

namespace PhpstanContracts\Tests\Fixtures;

use PhpstanContracts\Attribute\Ensures;
use PhpstanContracts\Attribute\Pure;
use PhpstanContracts\Attribute\Requires;

/**
 * `self::`, `static::` and `$this` inside an enum.
 */
enum Stock: int
{
    case InStock = 1;
    case OutOfStock = 2;
    case TemporarilyUnavailable = 5;

    public const DEFAULT = self::OutOfStock;

    public const int RESTOCK_DAYS = 14;

    #[Ensures('implies($quantity > 0, $result === self::InStock)')]
    #[Ensures('implies($quantity <= 0, $result === Stock::OutOfStock)')]
    public static function fromQuantity(int $quantity): self
    {
        if ($quantity > 0) {
            return self::InStock;
        }

        return self::OutOfStock;
    }

    #[Ensures('$result === static::InStock')]
    public static function staticIsTheEnumItself(): self
    {
        return static::InStock;
    }

    #[Ensures('implies($value === 5, $result === self::TemporarilyUnavailable)')]
    #[Ensures('implies($value === 3, $result === null)')]
    public static function tryFromThroughSelf(int $value): ?self
    {
        return self::tryFrom($value);
    }

    #[Ensures('$result === self::OutOfStock')]
    public static function constantHoldingACase(): self
    {
        return self::DEFAULT;
    }

    #[Ensures('implies($this === Stock::InStock, $result)')]
    #[Ensures('implies($this === Stock::TemporarilyUnavailable, !$result)')]
    public function isPurchasable(): bool
    {
        return match ($this) {
            self::InStock => true,
            self::OutOfStock, self::TemporarilyUnavailable => false,
        };
    }

    #[Ensures('$result === ($this === self::InStock)')]
    public function identityOfThis(): bool
    {
        return $this === self::InStock;
    }

    #[Ensures('$result === $this->value')]
    public function valueOfThis(): int
    {
        return match ($this) {
            self::InStock => 1,
            self::OutOfStock => 2,
            self::TemporarilyUnavailable => 5,
        };
    }

    #[Ensures('strlen($result) >= 7')]
    #[Ensures('implies($this === self::OutOfStock, $result === "OutOfStock")')]
    public function nameOfThis(): string
    {
        return $this->name;
    }

    /** @param int<0, 30> $days */
    #[Requires('$this !== self::InStock')]
    #[Ensures('$result === ($days >= self::RESTOCK_DAYS)')]
    public function thisInAPreconditionOnly(int $days): bool
    {
        return $days >= self::RESTOCK_DAYS;
    }

    #[Ensures('implies($this === Stock::TemporarilyUnavailable, !$result)')]
    public function isPurchasableBroken(): bool
    {
        return match ($this) {
            self::InStock, self::TemporarilyUnavailable => true,
            self::OutOfStock => false,
        };
    }

    #[Pure]
    public function partialMatchOnThis(): int
    {
        return match ($this) {
            self::InStock => 1,
            self::OutOfStock => 2,
        };
    }

    #[Pure]
    public function methodCallOnThis(): bool
    {
        return $this->identityOfThis();
    }

    #[Ensures('$this === self::InStock')]
    public static function noThisInAStaticMethod(): int
    {
        return 1;
    }
}
