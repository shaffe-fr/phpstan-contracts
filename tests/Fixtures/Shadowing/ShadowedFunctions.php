<?php

declare(strict_types=1);

namespace PhpstanContracts\Tests\Fixtures\Shadowing;

use PhpstanContracts\Attribute\Ensures;

/*
 * In this namespace, an unqualified call to one of these names calls the function
 * below, not the built-in one, and each returns something else than the built-in.
 */

function abs(int $x): int
{
    return 0;
}

function strlen(string $s): int
{
    return 99;
}

function max(int $a, int $b): int
{
    return $a;
}

function str_contains(string $haystack, string $needle): bool
{
    return false;
}

function floor(float $x): float
{
    return 0.5;
}

function is_null(mixed $value): bool
{
    return true;
}

final class ShadowedFunctions
{
    #[Ensures('$result === 5')]
    public static function absOfTheNamespace(): int
    {
        return abs(-5);
    }

    #[Ensures('$result === 0')]
    public static function strlenOfTheNamespace(): int
    {
        return strlen('');
    }

    #[Ensures('$result === 2')]
    public static function maxOfTheNamespace(): int
    {
        return max(1, 2);
    }

    #[Ensures('$result')]
    public static function strContainsOfTheNamespace(): bool
    {
        return str_contains('abc', 'b');
    }

    #[Ensures('$result === 1.0')]
    public static function floorOfTheNamespace(): float
    {
        return floor(1.5);
    }

    #[Ensures('$result === ($x === null)')]
    public static function isNullOfTheNamespace(?int $x): bool
    {
        return is_null($x);
    }

    /** A contract is read in the namespace too. */
    #[Ensures('$result === abs($x)')]
    public static function absOfTheNamespaceInAContract(int $x): int
    {
        return $x;
    }

    /** A fully qualified name is the built-in function. */
    #[Ensures('$result === 5')]
    public static function theBuiltInAbs(): int
    {
        return \abs(-5);
    }

    /** The namespace declares no intdiv(), so PHP falls back to the built-in function. */
    #[Ensures('$result === 3')]
    public static function aBuiltInThatTheNamespaceDoesNotDeclare(): int
    {
        return intdiv(7, 2);
    }
}
