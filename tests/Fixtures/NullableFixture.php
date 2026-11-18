<?php

declare(strict_types=1);

namespace PhpstanContracts\Tests\Fixtures;

use PhpstanContracts\Attribute\Ensures;
use PhpstanContracts\Attribute\Pure;

final class NullableFixture
{
    /** @param int<0, 100>|null $discount */
    #[Ensures('$result >= 0 && $result <= 100')]
    #[Ensures('implies($discount === null, $result === 0)')]
    #[Ensures('implies($discount !== null, $result === $discount)')]
    public static function coalesce(?int $discount): int
    {
        return $discount ?? 0;
    }

    /** @param int<0, 10>|null $x */
    #[Ensures('$result >= 0 && $result <= 11')]
    public static function earlyReturnNarrows(?int $x): int
    {
        if ($x === null) {
            return 0;
        }

        return $x + 1;
    }

    /** @param int<0, 10>|null $x */
    #[Ensures('$result >= 0 && $result <= 11')]
    public static function ifBranchNarrows(?int $x): int
    {
        if ($x !== null) {
            return $x + 1;
        }

        return 0;
    }

    /** @param int<0, 10>|null $x */
    #[Ensures('$result >= 0 && $result <= 11')]
    public static function elseBranchNarrows(?int $x): int
    {
        if ($x === null) {
            $y = 0;
        } else {
            $y = $x + 1;
        }

        return $y;
    }

    /** @param int<0, 10>|null $x */
    #[Ensures('$result >= 0 && $result <= 11')]
    public static function ternaryNarrows(?int $x): int
    {
        return $x !== null ? $x + 1 : 0;
    }

    /** @param int<0, 10>|null $x */
    #[Ensures('$result >= 0 && $result <= 11')]
    public static function issetNarrows(?int $x): int
    {
        if (isset($x)) {
            return $x + 1;
        }

        return 0;
    }

    /** @param int<0, 10>|null $x */
    #[Ensures('$result >= 0 && $result <= 11')]
    public static function isNullNarrows(?int $x): int
    {
        if (is_null($x)) {
            return 0;
        }

        return $x + 1;
    }

    /** @param int<0, 10>|null $x */
    #[Ensures('$result >= 0 && $result <= 11')]
    public static function matchTrueNarrows(?int $x): int
    {
        return match (true) {
            $x === null => 0,
            default => $x + 1,
        };
    }

    /** @param int<0, 10>|null $x */
    #[Ensures('$result >= 0 && $result <= 5')]
    public static function disjunctionNarrowsItsRightSide(?int $x): int
    {
        if ($x === null || $x > 5) {
            return 0;
        }

        return $x;
    }

    /** @param int<0, 10>|null $x */
    #[Ensures('$result === ($x !== null && $x > 5)')]
    public static function conjunctionNarrowsItsRightSide(?int $x): bool
    {
        return $x !== null && $x > 5;
    }

    /** @param int<0, 10>|null $x */
    #[Ensures('$result >= 0 && $result <= 10')]
    public static function assignmentNarrows(?int $x): int
    {
        if ($x === null) {
            $x = 4;
        }

        return $x;
    }

    /** @param int<0, 10>|null $x */
    #[Ensures('$result >= 0 && $result <= 10')]
    public static function coalesceAssignment(?int $x): int
    {
        $x ??= 4;

        return $x;
    }

    /** @param int<0, 10> $x */
    #[Ensures('implies($x > 5, $result === null)')]
    #[Ensures('implies($x <= 5, $result === $x)')]
    public static function nullableResult(int $x): ?int
    {
        if ($x > 5) {
            return null;
        }

        return $x;
    }

    /** @param int<0, 10>|null $x */
    #[Ensures('implies($x === null, $result === null)')]
    #[Ensures('implies($x !== null, $result === $x + 1)')]
    public static function nullPropagates(?int $x): ?int
    {
        return $x === null ? null : $x + 1;
    }

    #[Ensures('$result === ($a === null || $b === null)')]
    public static function nullableBooleans(?bool $a, ?bool $b): bool
    {
        return $a === null || $b === null;
    }

    #[Ensures('implies($a === null && $b === null, $result === true)')]
    #[Ensures('implies($a === 1.5 && $b === 1.5, $result === true)')]
    #[Ensures('implies($a === null && $b !== null, $result === false)')]
    public static function nullableIdentity(?float $a, ?float $b): bool
    {
        return $a === $b;
    }

    /** @param int<1, 1000>|null $limit */
    #[Ensures('$result >= 1 && $result <= 100')]
    public static function clampedOptionalLimit(?int $limit): int
    {
        if ($limit === null) {
            return 20;
        }

        return min($limit, 100);
    }

    /** @param int<1, 1000>|null $limit */
    #[Ensures('$result >= 1 && $result <= 100')]
    public static function forgottenClamp(?int $limit): int
    {
        return $limit ?? 20;
    }

    /** @param int<0, 10>|null $x */
    #[Ensures('$result >= 0')]
    public static function narrowingIsForgottenAfterAReassignment(?int $x, ?int $y): int
    {
        if ($x === null) {
            return 0;
        }
        $x = $y;

        return $x + 1;
    }

    /** @param int<0, 100>|null $percent */
    #[Pure]
    public static function arithmeticOnANullableValue(?int $percent): int
    {
        return $percent + 1;
    }

    #[Pure]
    public static function looseComparisonWithNull(?int $x): bool
    {
        return $x == null;
    }

    #[Pure]
    public static function nullWithoutAType(): int
    {
        $x = null;

        return 1;
    }

    #[Pure]
    public static function anIntegerVariableCannotBecomeNull(int $x): int
    {
        $y = $x;
        $y = null;

        return 1;
    }
}
