<?php

declare(strict_types=1);

namespace PhpstanContracts\Tests\Fixtures;

use PhpstanContracts\Attribute\Ensures;
use PhpstanContracts\Attribute\Pure;
use PhpstanContracts\Attribute\Requires;

final class FloatFixture
{
    #[Requires('!is_nan($x)')]
    #[Ensures('$result === $x')]
    public static function negationIsItsOwnInverse(float $x): float
    {
        return -(-$x);
    }

    #[Requires('is_finite($a) && is_finite($b)')]
    #[Ensures('$result === $b + $a')]
    public static function additionIsCommutative(float $a, float $b): float
    {
        return $a + $b;
    }

    #[Requires('!is_nan($x)')]
    #[Ensures('$result >= 0.0')]
    public static function absoluteValueOfANumber(float $x): float
    {
        return abs($x);
    }

    #[Requires('!is_nan($x)')]
    #[Ensures('$result <= $x')]
    public static function floorIsNotAboveTheValue(float $x): float
    {
        return floor($x);
    }

    #[Requires('!is_nan($x)')]
    #[Ensures('$result >= $x')]
    public static function ceilIsNotBelowTheValue(float $x): float
    {
        return ceil($x);
    }

    #[Requires('$x >= 0.0')]
    #[Ensures('$result >= 0.0')]
    public static function squareRootOfANonNegative(float $x): float
    {
        return sqrt($x);
    }

    #[Ensures('$result === 7')]
    public static function integerSurvivesAConversionToFloatAndBack(): int
    {
        return (int) (float) 7;
    }

    #[Ensures('$result === 1.5')]
    public static function anIntegerIsConvertedWhenMixedWithAFloat(): float
    {
        return 1 + 0.5;
    }

    #[Ensures('is_infinite($result) && $result > 0.0')]
    public static function infinityConstant(): float
    {
        return INF;
    }

    #[Ensures('is_nan($result) && $result !== $result')]
    public static function nanIsNotIdenticalToItself(): float
    {
        return NAN;
    }

    #[Ensures('$result === 0.0')]
    public static function negativeZeroIsIdenticalToZero(): float
    {
        return -0.0;
    }

    #[Ensures('$result === 0.3')]
    public static function oneTenthPlusTwoTenthsIsNotThreeTenths(): float
    {
        return 0.1 + 0.2;
    }

    #[Ensures('$result >= 0.0')]
    public static function absoluteValueOfNanIsNotNonNegative(float $x): float
    {
        return abs($x);
    }

    #[Requires('is_finite($a) && is_finite($b) && is_finite($c)')]
    #[Ensures('$result === $a + ($b + $c)')]
    public static function additionIsNotAssociative(float $a, float $b, float $c): float
    {
        return ($a + $b) + $c;
    }

    #[Ensures('$result === 1999')]
    public static function floorOfAPriceInCents(): int
    {
        return (int) floor(19.99 * 100.0);
    }

    #[Pure]
    public static function floatDivisionByZero(float $a, float $b): float
    {
        return $a / $b;
    }

    #[Pure]
    public static function castOfNanOrOutOfRange(float $x): int
    {
        return (int) $x;
    }

    #[Pure]
    public static function roundIsRejected(float $x): float
    {
        return round($x);
    }

    #[Pure]
    public static function aFloatIsNeverIdenticalToAnInt(int $i): bool
    {
        return $i === 1.0;
    }

    #[Pure]
    public static function minOfFloatsIsRejected(float $a, float $b): float
    {
        return min($a, $b);
    }

    #[Pure]
    public static function moduloOfFloatsIsRejected(float $a, float $b): float
    {
        return $a % $b;
    }

    #[Pure]
    public static function dividingTwoIntegersIsRejected(int $a, int $b): int
    {
        return $a / $b;
    }
}
