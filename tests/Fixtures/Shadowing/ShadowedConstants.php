<?php

declare(strict_types=1);

namespace PhpstanContracts\Tests\Fixtures\Shadowing;

use PhpstanContracts\Attribute\Ensures;

/*
 * In this namespace, an unqualified PHP_INT_MAX or NAN reads the constant below,
 * not the built-in one.
 */

const PHP_INT_MAX = 5;

const NAN = 0.0;

final class ShadowedConstants
{
    #[Ensures('$result === \PHP_INT_MAX')]
    public static function phpIntMaxOfTheNamespace(): int
    {
        return PHP_INT_MAX;
    }

    #[Ensures('is_nan($result)')]
    public static function nanOfTheNamespace(): float
    {
        return NAN;
    }

    /** A contract is read in the namespace too. */
    #[Ensures('$result === PHP_INT_MAX')]
    public static function phpIntMaxOfTheNamespaceInAContract(): int
    {
        return \PHP_INT_MAX;
    }

    /** A fully qualified name is the built-in constant. */
    #[Ensures('$result === \PHP_INT_MAX')]
    public static function theBuiltInPhpIntMax(): int
    {
        return \PHP_INT_MAX;
    }

    /** The namespace declares no PHP_INT_MIN, so PHP falls back to the built-in constant. */
    #[Ensures('$result < 0')]
    public static function aBuiltInThatTheNamespaceDoesNotDeclare(): int
    {
        return PHP_INT_MIN;
    }

    /** Only true, false and null are case-insensitive: php_int_max is an undefined constant. */
    #[Ensures('$result > 0')]
    public static function aBuiltInWithTheWrongCase(): int
    {
        return php_int_max;
    }
}
