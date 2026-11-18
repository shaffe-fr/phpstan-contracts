<?php

declare(strict_types=1);

namespace PhpstanContracts\Examples\RealWorld;

use PhpstanContracts\Attribute\Ensures;
use PhpstanContracts\Attribute\Requires;

final class Floats
{
    /**
     * Midpoint of a range, as it is usually first written.
     */
    #[Requires('is_finite($low) && is_finite($high) && $low <= $high')]
    #[Ensures('is_finite($result)')]
    public static function midpointBroken(float $low, float $high): float
    {
        return ($low + $high) * 0.5;
    }

    /**
     * The textbook fix for the integer version: subtract first.
     * With floats, $high - $low overflows when the bounds are huge and of opposite signs.
     */
    #[Requires('is_finite($low) && is_finite($high) && $low <= $high')]
    #[Ensures('is_finite($result)')]
    public static function midpointSubtractBroken(float $low, float $high): float
    {
        return $low + ($high - $low) * 0.5;
    }

    /**
     * Halving each bound first cannot overflow.
     */
    #[Requires('is_finite($low) && is_finite($high) && $low <= $high')]
    #[Ensures('is_finite($result)')]
    public static function midpoint(float $low, float $high): float
    {
        return $low * 0.5 + $high * 0.5;
    }

    /**
     * Converts a price to cents the way it is often written. Not every price survives it.
     */
    #[Ensures('$result === 1999')]
    public static function listPriceCents(): int
    {
        return (int) floor(19.99 * 100.0);
    }

    /**
     * Adding 20% VAT to a non-negative net price never lowers it.
     */
    #[Requires('is_finite($net) && $net >= 0.0')]
    #[Ensures('$result >= $net')]
    #[Ensures('!is_nan($result)')]
    public static function withVat(float $net): float
    {
        return $net * 1.2;
    }

    /**
     * Regrouping a sum: float addition is not associative.
     */
    #[Requires('is_finite($a) && is_finite($b) && is_finite($c)')]
    #[Ensures('$result === $a + ($b + $c)')]
    public static function sumRegrouped(float $a, float $b, float $c): float
    {
        return ($a + $b) + $c;
    }

    /**
     * abs() of NAN is NAN, and NAN is not >= 0.0.
     */
    #[Ensures('$result >= 0.0')]
    public static function magnitude(float $x): float
    {
        return abs($x);
    }

    /**
     * With NaN excluded, the same contract holds.
     */
    #[Requires('!is_nan($x)')]
    #[Ensures('$result >= 0.0')]
    public static function magnitudeOfNumber(float $x): float
    {
        return abs($x);
    }
}
