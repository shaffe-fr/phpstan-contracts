<?php

declare(strict_types=1);

namespace PhpstanContracts\Tests\Fixtures;

use PhpstanContracts\Attribute\Ensures;
use PhpstanContracts\Attribute\Pure;

/**
 * Every method here is refuted. The replay test runs each one in PHP with the
 * values of the reported counterexample and checks that PHP agrees with Z3.
 */
final class ReplayFixture
{
    /**
     * @param int<0, 100000000> $totalItems
     * @param int<1, 1000> $perPage
     */
    #[Ensures('$totalItems === 0 || ($result - 1) * $perPage < $totalItems')]
    public static function pageCount(int $totalItems, int $perPage): int
    {
        return intdiv($totalItems, $perPage) + 1;
    }

    /**
     * @param int<-1000000000, 1000000000> $net
     * @param int<0, 3000> $rate
     */
    #[Ensures('abs($result * 10000 - $net * $rate) <= 5000')]
    public static function vat(int $net, int $rate): int
    {
        return intdiv($net * $rate + 5000, 10000);
    }

    /**
     * @param int<0, 100> $discount
     * @param int<0, 1000> $nights
     */
    #[Ensures('$result >= 0')]
    public static function negativeTotal(int $discount, int $nights): int
    {
        return $nights * 10 - ($discount + 5) * $nights;
    }

    #[Ensures('$result >= 0.0')]
    public static function absoluteValue(float $x): float
    {
        return abs($x);
    }

    #[Ensures('$result === $a + ($b + $c)')]
    public static function regrouped(float $a, float $b, float $c): float
    {
        return ($a + $b) + $c;
    }

    #[Ensures('is_finite($result)')]
    public static function midpoint(float $low, float $high): float
    {
        return ($low + $high) * 0.5;
    }

    #[Ensures('implies($result, str_ends_with($file, ".png") || str_ends_with($file, ".jpg"))')]
    public static function allowedImage(string $file): bool
    {
        return str_contains($file, '.png') || str_contains($file, '.jpg');
    }

    #[Ensures('!str_starts_with($result, "/")')]
    public static function storageKey(string $tenant, string $name): string
    {
        return $tenant . '/' . $name;
    }

    #[Ensures('strlen($result) === strlen($a)')]
    public static function concatenation(string $a, string $b): string
    {
        return $a . $b;
    }

    /** @param int<1, 1000>|null $limit */
    #[Ensures('$result >= 1 && $result <= 100')]
    public static function forgottenClamp(?int $limit): int
    {
        return $limit ?? 20;
    }

    #[Ensures('$result === ($a ?? 0) + 1')]
    public static function nullableArithmetic(?bool $flag, ?int $a): int
    {
        return $a === null ? 1 : $a + 2;
    }

    #[Ensures('$result === $left')]
    public static function booleanLogic(bool $left, bool $right): bool
    {
        return $left && $right;
    }

    /** @param int<0, 20> $n */
    #[Ensures('$result >= 0')]
    public static function minimumThenSubtraction(int $n): int
    {
        return min($n, 10) - 5;
    }

    #[Ensures('$result === 0')]
    public static function moduloKeepsTheSignOfTheDividend(int $a): int
    {
        return $a % 3;
    }

    #[Ensures('$result === 0')]
    public static function truncatedQuotient(int $a): int
    {
        return intdiv($a, 3);
    }

    #[Ensures('$result === 1999')]
    public static function priceToCents(): int
    {
        return (int) floor(19.99 * 100.0);
    }

    #[Ensures('$result > 0')]
    public static function enumValue(Level $level): int
    {
        return $level->value - 1;
    }

    #[Pure]
    public static function overflow(int $a, int $b): int
    {
        return $a * $b;
    }
}
