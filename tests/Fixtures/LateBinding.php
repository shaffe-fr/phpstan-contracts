<?php

declare(strict_types=1);

namespace PhpstanContracts\Tests\Fixtures;

use PhpstanContracts\Attribute\Ensures;
use PhpstanContracts\Attribute\Pure;

/**
 * Not final: a subclass can override LIMIT, so `static::LIMIT` has no single value.
 */
class LateBinding
{
    public const int LIMIT = 100;

    final public const int HARD_LIMIT = 1000;

    #[Ensures('$result <= 100')]
    public static function selfIsStaticallyBound(int $value): int
    {
        return min($value, self::LIMIT);
    }

    #[Ensures('$result <= self::HARD_LIMIT')]
    public static function finalConstantThroughStatic(int $value): int
    {
        return min($value, static::HARD_LIMIT);
    }

    #[Ensures('$result <= LateBinding::LIMIT')]
    public static function classNameIsStaticallyBound(int $value): int
    {
        return min($value, LateBinding::LIMIT);
    }

    #[Pure]
    public static function overridableConstantThroughStatic(int $value): int
    {
        return min($value, static::LIMIT);
    }

    #[Pure]
    public static function lateBoundCall(int $value): int
    {
        return static::from($value);
    }

    public static function from(int $value): int
    {
        return $value;
    }

    #[Ensures('$result <= static::LIMIT')]
    public static function lateBindingInAContract(int $value): int
    {
        return min($value, self::LIMIT);
    }
}
