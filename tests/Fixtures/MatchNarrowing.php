<?php

declare(strict_types=1);

namespace PhpstanContracts\Tests\Fixtures;

use PhpstanContracts\Attribute\Ensures;
use PhpstanContracts\Attribute\Pure;

/**
 * An arm of `match (true)` runs when any of its conditions holds, so an arm with
 * several conditions tells its body nothing about each one.
 */
final class MatchNarrowing
{
    /**
     * `eitherNotNull(null, 1)` takes the first arm through `$b !== null` and returns null.
     */
    #[Ensures('$result !== null')]
    public static function eitherNotNull(?int $a, ?int $b): ?int
    {
        return match (true) {
            $a !== null, $b !== null => $a,
            default => 0,
        };
    }

    /**
     * With `$a` null, the arm is taken through `$b !== null` and the division runs.
     */
    #[Pure]
    public static function divisionBehindTheOtherCondition(?int $a, ?int $b, int $c): int
    {
        return match (true) {
            $a !== null, $b !== null => $a ?? intdiv(100, $c),
            default => 0,
        };
    }

    #[Ensures('$result === ($a ?? 0)')]
    public static function aSingleConditionNarrows(?int $a): int
    {
        return match (true) {
            $a !== null => $a,
            default => 0,
        };
    }
}
