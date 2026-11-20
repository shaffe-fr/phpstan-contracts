<?php

declare(strict_types=1);

namespace PhpstanContracts\Tests\Fixtures\Shadowing;

use PhpstanContracts\Attribute\Ensures;

/**
 * In this namespace, an unqualified in_array() calls this function, not the built-in one.
 *
 * @param array<mixed> $haystack
 */
function in_array(mixed $needle, array $haystack, bool $strict = false): bool
{
    return true;
}

final class ShadowedInArray
{
    #[Ensures('$result === ($n === 1)')]
    public static function callsTheFunctionOfTheNamespace(int $n): bool
    {
        return in_array($n, [1], true);
    }

    #[Ensures('$result === ($n === 1)')]
    public static function callsTheBuiltInFunction(int $n): bool
    {
        return \in_array($n, [1], true);
    }
}
