<?php

declare(strict_types=1);

namespace PhpstanContracts\Tests\Fixtures;

use PhpstanContracts\Attribute\Ensures;
use PhpstanContracts\Attribute\Pure;
use PhpstanContracts\Attribute\Requires;

final class IntegerFixture
{
    /**
     * @param int<0, 100> $a
     * @param int<0, 100> $b
     */
    #[Ensures('$result === $a + $b')]
    public static function sum(int $a, int $b): int
    {
        return $a + $b;
    }

    /** @param int<-100, 100> $x */
    #[Ensures('$result >= 0 && $result <= 100')]
    public static function earlyReturn(int $x): int
    {
        if ($x < 0) {
            return 0;
        }

        return $x;
    }

    /** @param int<0, 100> $score */
    #[Ensures('$result >= 1 && $result <= 4')]
    #[Ensures('implies($score >= 90, $result === 4)')]
    #[Ensures('implies($score < 50, $result === 1)')]
    public static function elseIfChain(int $score): int
    {
        if ($score >= 90) {
            $grade = 4;
        } elseif ($score >= 70) {
            $grade = 3;
        } elseif ($score >= 50) {
            $grade = 2;
        } else {
            $grade = 1;
        }

        return $grade;
    }

    /** @param int<0, 10> $x */
    #[Ensures('$result === ($x + 2) * 3 - 1')]
    public static function entryValuesSurviveReassignment(int $x): int
    {
        $x += 2;
        $x *= 3;
        $x -= 1;

        return $x;
    }

    /** @param int<0, 100> $x */
    #[Ensures('$result === ($x > 50 ? 1 : 0)')]
    public static function ternary(int $x): int
    {
        return $x > 50 ? 1 : 0;
    }

    /** @param int<0, 5> $x */
    #[Ensures('$result >= 0 && $result <= 20')]
    public static function matchWithDefault(int $x): int
    {
        return match ($x) {
            1 => 10,
            2, 3 => 20,
            default => 0,
        };
    }

    /** @param int<0, 100> $x */
    #[Ensures('$result >= 1 && $result <= 3')]
    #[Ensures('implies($x > 80, $result === 3)')]
    public static function matchTrue(int $x): int
    {
        return match (true) {
            $x > 80 => 3,
            $x > 40 => 2,
            default => 1,
        };
    }

    /**
     * The four laws that define truncated division, for every pair of ints. Only one
     * (quotient, remainder) pair satisfies all four, so proving them for `intdiv()`
     * and `%` proves that the translation of both is PHP's.
     */
    #[Requires('$b !== 0 && ($a !== PHP_INT_MIN || $b !== -1)')]
    #[Ensures('intdiv($a, $b) * $b + $a % $b === $a')]
    #[Ensures('abs($a % $b) < abs($b)')]
    #[Ensures('$a % $b === 0 || ($a % $b < 0) === ($a < 0)')]
    #[Ensures('abs(intdiv($a, $b) * $b) <= abs($a)')]
    public static function divisionAndModuloObeyTheLawsOfTruncatedDivision(int $a, int $b): bool
    {
        return true;
    }

    /**
     * @param int<-50, 50> $a
     * @param int<-50, 50> $b
     */
    #[Ensures('$result >= $a && $result >= $b')]
    #[Ensures('$result === $a || $result === $b')]
    public static function maximumOfTwo(int $a, int $b): int
    {
        return max($a, $b);
    }

    /**
     * @param int<-50, 50> $a
     * @param int<-50, 50> $b
     */
    #[Ensures('$result <= $a && $result <= $b')]
    #[Ensures('$result === $a || $result === $b')]
    public static function minimumOfTwo(int $a, int $b): int
    {
        return min($a, $b);
    }

    /** @param int<-50, 50> $x */
    #[Ensures('$result >= 0 && $result <= 50')]
    #[Ensures('$result === $x || $result === -$x')]
    public static function absoluteValue(int $x): int
    {
        return abs($x);
    }

    /**
     * @param int<0, 100> $a
     * @param int<-5, 5> $b
     */
    #[Ensures('$result >= 0')]
    public static function divisionGuardedByAnd(int $a, int $b): int
    {
        if ($b > 0 && intdiv($a, $b) > 3) {
            return 1;
        }

        return 0;
    }

    /**
     * @param int<0, 100> $a
     * @param int<-5, 5> $b
     */
    #[Ensures('$result >= 0')]
    public static function divisionGuardedByTernary(int $a, int $b): int
    {
        return $b !== 0 ? intdiv($a, $b) * $b : 0;
    }

    /** @param int<0, 10> $x */
    #[Ensures('implies($x > 5, $result > 5)')]
    #[Ensures('implies($x <= 5, $result === 0)')]
    public static function implication(int $x): int
    {
        return $x > 5 ? $x : 0;
    }

    /**
     * @param int<0, 100> $a
     * @param int<0, 100> $b
     */
    #[Pure]
    public static function pureOnlyIsProvenSafe(int $a, int $b): int
    {
        return $a * $b - $b;
    }

    /**
     * @param int<0, 100> $a
     * @param int<0, 100> $b
     */
    #[Requires('$a <= $b')]
    #[Ensures('$result >= 0')]
    public static function requiresRelatesParameters(int $a, int $b): int
    {
        return $b - $a;
    }

    /**
     * @param int<0, 10> $x
     * @return int<0, 20>
     */
    #[Pure]
    public static function returnRangeIsRespected(int $x): int
    {
        return $x * 2;
    }

    /** @param int<0, 100> $x */
    #[Ensures('$result >= 0')]
    #[Ensures('$result > 100')]
    #[Ensures('$result <= 100')]
    public static function onlyTheFailingClauseIsReported(int $x): int
    {
        return $x;
    }

    /** @param int<0, 100> $x */
    #[Ensures('$result > 50')]
    public static function postconditionViolated(int $x): int
    {
        return $x;
    }

    #[Pure]
    public static function additionOverflows(int $a, int $b): int
    {
        return $a + $b;
    }

    #[Pure]
    public static function multiplicationOverflows(int $a, int $b): int
    {
        return $a * $b;
    }

    #[Pure]
    public static function negationOverflows(int $a): int
    {
        return -$a;
    }

    #[Pure]
    public static function absoluteValueOverflows(int $a): int
    {
        return abs($a);
    }

    /**
     * @param int<-100, 100> $a
     * @param int<-100, 100> $b
     */
    #[Pure]
    public static function divisionByZero(int $a, int $b): int
    {
        return intdiv($a, $b);
    }

    /**
     * @param int<-100, 100> $a
     * @param int<-100, 100> $b
     */
    #[Pure]
    public static function moduloByZero(int $a, int $b): int
    {
        return $a % $b;
    }

    #[Pure]
    public static function minimumDividedByMinusOneOverflows(int $a, int $b): int
    {
        return intdiv($a, $b);
    }

    /**
     * @param int<0, 100> $a
     * @param int<-5, 5> $b
     */
    #[Pure]
    public static function guardOfTheWrongOperand(int $a, int $b): int
    {
        if ($b > 0 || intdiv($a, $b) > 3) {
            return 1;
        }

        return 0;
    }

    /** @param int<0, 5> $x */
    #[Pure]
    public static function matchDoesNotCoverEveryValue(int $x): int
    {
        return match ($x) {
            0 => 1,
            1 => 2,
        };
    }

    /** @param int<-5, 5> $x */
    #[Pure]
    public static function pathWithoutReturn(int $x): int
    {
        if ($x > 0) {
            return 1;
        }

        $unused = $x;
    }

    #[Requires('$x > 10')]
    #[Requires('$x < 5')]
    #[Ensures('$result === 42')]
    public static function contradictoryPreconditions(int $x): int
    {
        return $x;
    }

    /**
     * @param int<0, 10> $x
     * @return int<0, 10>
     */
    #[Pure]
    public static function returnRangeIsViolated(int $x): int
    {
        return $x + 1;
    }
}
