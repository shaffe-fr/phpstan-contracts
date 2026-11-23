<?php

declare(strict_types=1);

namespace PhpstanContracts\Tests\Fixtures;

use PhpstanContracts\Attribute\Ensures;
use PhpstanContracts\Attribute\Requires;

/**
 * `$b ? null : 5` is a nullable int that no parameter, field or variable declares:
 * its sort must be declared all the same.
 */
final class LiteralNullables
{
    #[Requires('$b === true')]
    #[Ensures('$result === 1')]
    public static function comparesALiteralNullableWithNull(bool $b): int
    {
        return ($b ? null : 5) === null ? 1 : 2;
    }

    #[Requires('$b === false')]
    #[Ensures('$result === 2')]
    public static function isNullOfALiteralNullable(bool $b): int
    {
        return is_null($b ? null : 5) ? 1 : 2;
    }

    #[Ensures('$result === 1')]
    public static function aClaimThatFails(bool $b): int
    {
        return ($b ? null : 5) === null ? 1 : 2;
    }
}
