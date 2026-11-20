<?php

declare(strict_types=1);

namespace PhpstanContracts\Tests\Fixtures;

use PhpstanContracts\Attribute\Ensures;
use PhpstanContracts\Attribute\Pure;

/**
 * An int converted to its decimal string by `.`, interpolation and `(string)`.
 */
final class IntToStringFixture
{
    /** @param int<1, 30> $days */
    #[Ensures('implies($days === 1, $result === "1 jour")')]
    #[Ensures('implies($days === 3, $result === "3 jours")')]
    #[Ensures('implies($days > 1, $result === $days . " jours")')]
    public static function inStockDelay(int $days): string
    {
        return $days === 1 ? '1 jour' : "$days jours";
    }

    /** @param int<1, 30> $days */
    #[Ensures('implies($days === 1, $result === "1 jour")')]
    public static function inStockDelayBroken(int $days): string
    {
        return $days . ' jours';
    }

    #[Ensures('$result === "-42"')]
    public static function aNegativeInt(): string
    {
        return (string) -42;
    }

    #[Ensures('$result === "-9223372036854775808"')]
    public static function theSmallestInt(): string
    {
        return (string) PHP_INT_MIN;
    }

    /** @param int<0, 999> $n */
    #[Ensures('strlen($result) >= 1 && strlen($result) <= 3')]
    public static function digits(int $n): string
    {
        return (string) $n;
    }

    /** @param int<0, 9999> $n */
    #[Ensures('strlen($result) <= 3')]
    public static function digitsBroken(int $n): string
    {
        return (string) $n;
    }

    #[Ensures('$result === "0" || !str_starts_with($result, "0")')]
    public static function noLeadingZero(int $n): string
    {
        return (string) $n;
    }

    #[Ensures('$result === "Order #$id"')]
    #[Ensures('str_starts_with($result, "Order #")')]
    public static function interpolationWithBraces(int $id): string
    {
        return "Order #{$id}";
    }

    #[Ensures('$result === "(" . $point->x . ", " . $point->y . ")"')]
    public static function interpolatedProperties(Point $point): string
    {
        return "($point->x, $point->y)";
    }

    #[Ensures('$result === "n=" . $n')]
    public static function concatAssign(int $n): string
    {
        $text = 'n=';
        $text .= $n;

        return $text;
    }

    #[Ensures('$result === $text')]
    public static function aStringCastIsTheString(string $text): string
    {
        return (string) $text;
    }

    #[Pure]
    public static function aFloatInAConcatenation(float $price): string
    {
        return 'Price: ' . $price;
    }

    #[Pure]
    public static function aBoolInAConcatenation(bool $flag): string
    {
        return 'Flag: ' . $flag;
    }

    #[Pure]
    public static function aNullableIntWithoutACheck(?int $n): string
    {
        return "n=$n";
    }

    #[Pure]
    public static function aFloatCastToString(float $price): string
    {
        return (string) $price;
    }

    #[Pure]
    public static function aBoolCast(int $n): bool
    {
        return (bool) $n;
    }
}
