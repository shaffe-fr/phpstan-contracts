<?php

declare(strict_types=1);

namespace PhpstanContracts\Tests\Fixtures;

use PhpstanContracts\Attribute\Ensures;
use PhpstanContracts\Attribute\Pure;
use PhpstanContracts\Attribute\Requires;

/**
 * `foreach` over a list: the forms that are proven or refuted, the bound of the unrolling and
 * the loops that are rejected, each with its reason.
 */
final class ForeachFixture
{
    public const MAX_LINES = 12;

    /**
     * @param list<int<0, 1000>> $amounts
     */
    #[Requires('count($amounts) <= 10')]
    #[Ensures('$result >= 0 && $result <= 1000 * count($amounts)')]
    public static function sumsTheElements(array $amounts): int
    {
        $total = 0;
        foreach ($amounts as $amount) {
            $total += $amount;
        }

        return $total;
    }

    /**
     * @param list<int<0, 1000>> $amounts
     */
    #[Requires('count($amounts) <= 10')]
    #[Ensures('$result === 0 || count($amounts) > 0')]
    #[Ensures('implies(count($amounts) === 0, $result === 0)')]
    public static function anEmptyListRunsTheBodyZeroTimes(array $amounts): int
    {
        $total = 0;
        foreach ($amounts as $amount) {
            $total += $amount + 1;
        }

        return $total;
    }

    /**
     * @param list<int<0, 1000>> $amounts
     */
    #[Requires('count($amounts) <= 10')]
    #[Ensures('$result >= 0 && $result <= 45')]
    public static function sumsTheKeys(array $amounts): int
    {
        $total = 0;
        foreach ($amounts as $index => $amount) {
            $total += $index;
        }

        return $total;
    }

    /**
     * @param list<int<0, 1000>> $amounts
     */
    #[Requires('count($amounts) <= 10')]
    #[Ensures('$result === 0')]
    public static function sumsTheKeysWrongly(array $amounts): int
    {
        $total = 0;
        foreach ($amounts as $index => $amount) {
            $total += $index;
        }

        return $total;
    }

    /**
     * @param list<int<0, 1000>> $amounts
     */
    #[Requires('count($amounts) <= 10')]
    #[Ensures('$result >= 0 && $result <= count($amounts)')]
    public static function countsTheLargeAmounts(array $amounts): int
    {
        $large = 0;
        foreach ($amounts as $amount) {
            if ($amount > 500) {
                $large += 1;
            }
        }

        return $large;
    }

    /**
     * @param list<int<0, 1000>> $amounts
     */
    #[Requires('count($amounts) <= 10')]
    #[Ensures('$result <= 2 * count($amounts)')]
    public static function weighsEachAmountWithAMatch(array $amounts): int
    {
        $weight = 0;
        foreach ($amounts as $amount) {
            $weight += match (true) {
                $amount > 500 => 2,
                $amount > 100 => 1,
                default => 0,
            };
        }

        return $weight;
    }

    /**
     * @param list<int<0, 1000>> $amounts
     */
    #[Requires('count($amounts) <= 10')]
    #[Ensures('$result <= 999')]
    public static function claimsAMaximumTheElementsCanExceed(array $amounts): int
    {
        $largest = 0;
        foreach ($amounts as $amount) {
            if ($amount > $largest) {
                $largest = $amount;
            }
        }

        return $largest;
    }

    /**
     * The key and the value keep the last iteration's values after the loop.
     *
     * @param non-empty-list<int<0, 1000>> $amounts
     */
    #[Requires('count($amounts) <= 5')]
    #[Ensures('$result === $amounts[count($amounts) - 1] + count($amounts) - 1')]
    public static function keepsTheLastKeyAndValue(array $amounts): int
    {
        $last = 0;
        $key = 0;
        foreach ($amounts as $key => $last) {
        }

        return $last + $key;
    }

    /**
     * @param list<int<0, 1000>> $amounts
     */
    #[Requires('count($amounts) <= 5')]
    public static function readsAVariableOnlyTheLoopDefines(array $amounts): int
    {
        foreach ($amounts as $amount) {
            $seen = $amount;
        }

        return $seen;
    }

    /**
     * @param list<int<0, 1000>> $amounts
     */
    #[Requires('count($amounts) <= 5')]
    public static function changesTheTypeOfAnAccumulator(array $amounts): float
    {
        $total = 0;
        foreach ($amounts as $amount) {
            $total += 0.5;
        }

        return $total;
    }

    /**
     * @param list<int<0, 1000>> $amounts
     */
    #[Requires('count($amounts) < 4')]
    #[Ensures('$result <= 3000')]
    public static function boundsTheListWithLessThan(array $amounts): int
    {
        $total = 0;
        foreach ($amounts as $amount) {
            $total += $amount;
        }

        return $total;
    }

    /**
     * @param list<int<0, 1000>> $amounts
     */
    #[Requires('3 >= count($amounts)')]
    #[Ensures('$result <= 3000')]
    public static function boundsTheListWithTheConstantFirst(array $amounts): int
    {
        $total = 0;
        foreach ($amounts as $amount) {
            $total += $amount;
        }

        return $total;
    }

    /**
     * @param list<int<0, 1000>> $amounts
     */
    #[Requires('4 > count($amounts)')]
    #[Ensures('$result <= 3000')]
    public static function boundsTheListWithGreaterThan(array $amounts): int
    {
        $total = 0;
        foreach ($amounts as $amount) {
            $total += $amount;
        }

        return $total;
    }

    /**
     * @param list<int<0, 1000>> $amounts
     */
    #[Requires('count($amounts) === 3')]
    #[Ensures('$result <= 3000')]
    public static function boundsTheListWithItsExactSize(array $amounts): int
    {
        $total = 0;
        foreach ($amounts as $amount) {
            $total += $amount;
        }

        return $total;
    }

    /**
     * @param list<int<0, 1000>> $amounts
     */
    #[Requires('count($amounts) <= self::MAX_LINES')]
    #[Ensures('$result <= 12000')]
    public static function boundsTheListWithAClassConstant(array $amounts): int
    {
        $total = 0;
        foreach ($amounts as $amount) {
            $total += $amount;
        }

        return $total;
    }

    /**
     * A bound that is true and that the loop does not need: `#[Requires]` only chooses how far to unroll.
     *
     * @param list<int<0, 1000>> $amounts
     */
    #[Requires('count($amounts) <= 2 && count($amounts) <= 6')]
    #[Ensures('$result <= 2000')]
    public static function usesTheSmallestBoundOfTheList(array $amounts): int
    {
        $total = 0;
        foreach ($amounts as $amount) {
            $total += $amount;
        }

        return $total;
    }

    /**
     * @param list<int<0, 1000>> $amounts
     */
    #[Requires('count($amounts) <= 2')]
    #[Ensures('$result <= 1999')]
    public static function claimsOneLessThanTheBoundAllows(array $amounts): int
    {
        $total = 0;
        foreach ($amounts as $amount) {
            $total += $amount;
        }

        return $total;
    }

    /**
     * @param list<int<0, 1000>> $amounts
     */
    #[Ensures('$result >= 0')]
    public static function hasNoBound(array $amounts): int
    {
        $total = 0;
        foreach ($amounts as $amount) {
            $total += $amount;
        }

        return $total;
    }

    /**
     * @param list<int<0, 1000>> $amounts
     */
    #[Requires('count($amounts) <= 12')]
    #[Ensures('$result >= 0')]
    public static function hasABoundAboveTheDefault(array $amounts): int
    {
        $total = 0;
        foreach ($amounts as $amount) {
            $total += $amount;
        }

        return $total;
    }

    /**
     * @param list<int<0, 1000>> $weights
     */
    #[Requires('count($weights) <= 3')]
    #[Ensures('$result >= 0')]
    public static function loopsOverAListBuiltFromABoundedOne(array $weights): int
    {
        $copy = [...$weights];
        $total = 0;
        foreach ($copy as $weight) {
            $total += $weight;
        }

        return $total;
    }

    /**
     * @param list<int<0, 1000>> $amounts
     * @param list<int<0, 1000>> $weights
     */
    #[Requires('count($weights) <= 3')]
    #[Ensures('$result >= 0')]
    public static function loopsOverAListTheBoundDoesNotMention(array $amounts, array $weights): int
    {
        $total = 0;
        foreach ($amounts as $amount) {
            $total += $amount;
        }

        return $total;
    }

    /**
     * @param list<int<0, 1000>> $weights
     */
    #[Requires('count($weights) <= 3')]
    #[Ensures('$result >= 0')]
    public static function loopsOverALocalListTheBoundDoesNotMention(array $weights): int
    {
        $copy = [...$weights, 1, 2];
        $total = 0;
        foreach ($copy as $weight) {
            $total += $weight;
        }

        return $total;
    }

    #[Ensures('$result === 6')]
    public static function loopsOverAListLiteral(): int
    {
        $total = 0;
        foreach ([1, 2, 3] as $number) {
            $total += $number;
        }

        return $total;
    }

    /**
     * @param list<int<0, 1000>> $amounts
     */
    #[Requires('count($amounts) <= 10')]
    #[Ensures('$result >= 0 && $result <= 1000')]
    public static function returnsFromTheBody(array $amounts): int
    {
        foreach ($amounts as $amount) {
            return $amount;
        }

        return 0;
    }

    /**
     * @param list<int> $amounts
     */
    #[Requires('count($amounts) <= 10')]
    public static function overflowsInTheSecondIteration(array $amounts): int
    {
        $total = 0;
        foreach ($amounts as $amount) {
            $total += $amount;
        }

        return $total;
    }

    /**
     * @param list<int<0, 1000>> $amounts
     */
    #[Requires('count($amounts) <= 5')]
    public static function readsOneElementTooFar(array $amounts): int
    {
        $total = 0;
        foreach ($amounts as $index => $amount) {
            $total += $amounts[$index + 1];
        }

        return $total;
    }

    /**
     * @param list<int<0, 1000>> $amounts
     */
    #[Requires('count($amounts) <= 500')]
    public static function overTheUnrollingBudget(array $amounts): int
    {
        $total = 0;
        foreach ($amounts as $amount) {
            $total += $amount;
        }

        return $total;
    }

    /**
     * @param list<int<0, 1000>> $amounts
     */
    #[Requires('count($amounts) <= 5')]
    public static function whileLoop(array $amounts): int
    {
        $i = 0;
        while ($i < count($amounts)) {
            $i += 1;
        }

        return $i;
    }

    /**
     * @param list<int<0, 1000>> $amounts
     */
    #[Pure]
    public static function byReference(array $amounts): int
    {
        foreach ($amounts as &$amount) {
            $amount = 0;
        }

        return 0;
    }

    /**
     * @param list<int<0, 1000>> $amounts
     */
    #[Pure]
    public static function byReferenceWithAKey(array $amounts): int
    {
        foreach ($amounts as $key => &$amount) {
            $amount = 0;
        }

        return 0;
    }

    /**
     * @param list<Pair> $pairs
     */
    #[Pure]
    public static function destructuresWithBrackets(array $pairs): int
    {
        $total = 0;
        foreach ($pairs as [$left, $right]) {
            $total += 1;
        }

        return $total;
    }

    /**
     * @param list<Pair> $pairs
     */
    #[Pure]
    public static function destructuresWithList(array $pairs): int
    {
        $total = 0;
        foreach ($pairs as list($left, $right)) {
            $total += 1;
        }

        return $total;
    }

    /**
     * @param list<int<0, 100>> $amounts
     */
    #[Pure]
    public static function writesAnElementOfTheObject(Pair $holder, array $amounts): int
    {
        foreach ($amounts as $holder->left) {
        }

        return 0;
    }

    /**
     * @param list<int<0, 1000>> $amounts
     */
    #[Pure]
    public static function usesTheSameVariableTwice(array $amounts): int
    {
        $total = 0;
        foreach ($amounts as $x => $x) {
            $total += 1;
        }

        return $total;
    }

    /**
     * @param array{net: int, vat: int} $shape
     */
    #[Pure]
    public static function overAShape(array $shape): int
    {
        $total = 0;
        foreach ($shape as $value) {
            $total += $value;
        }

        return $total;
    }

    /**
     * @param list<int<0, 1000>>|null $amounts
     */
    #[Pure]
    public static function overANullableList(?array $amounts): int
    {
        $total = 0;
        foreach ($amounts as $amount) {
            $total += $amount;
        }

        return $total;
    }

    #[Pure]
    public static function overAString(string $text): int
    {
        $total = 0;
        foreach ($text as $character) {
            $total += 1;
        }

        return $total;
    }

    #[Pure]
    public static function overAnEmptyLiteral(): int
    {
        $total = 0;
        foreach ([] as $value) {
            $total += 1;
        }

        return $total;
    }

    /**
     * The condition of the `if` bounds the list, and the bound obligation is proven under it.
     *
     * @param list<int<0, 1000>>|null $amounts
     */
    #[Ensures('$result >= 0')]
    public static function boundsTheListWithAGuard(?array $amounts): int
    {
        $total = 0;
        if ($amounts !== null && count($amounts) <= 5) {
            foreach ($amounts as $amount) {
                $total += $amount;
            }
        }

        return $total;
    }

    #[Pure]
    public static function overAnEmptyVariable(): int
    {
        $values = [];
        $total = 0;
        foreach ($values as $value) {
            $total += 1;
        }

        return $total;
    }
    /**
     * @param list<int<0, 1000>> $amounts
     * @return list<int<500, 1000>>
     */
    #[Requires('count($amounts) <= 10')]
    #[Ensures('count($result) <= count($amounts)')]
    public static function filtersTheLargeAmounts(array $amounts): array
    {
        $large = [];
        foreach ($amounts as $amount) {
            if ($amount >= 500) {
                $large[] = $amount;
            }
        }
        return $large;
    }
    /**
     * @param list<int<0, 1000>> $amounts
     * @return list<int<0, 2000>>
     */
    #[Requires('count($amounts) <= 10')]
    #[Ensures('count($result) === count($amounts)')]
    #[Ensures('count($amounts) === 0 || $result[0] === 2 * $amounts[0]')]
    public static function mapsEachAmountToItsDouble(array $amounts): array
    {
        $doubled = [];
        foreach ($amounts as $amount) {
            $doubled[] = 2 * $amount;
        }
        return $doubled;
    }
    /**
     * @param list<int<0, 1000>> $amounts
     * @return list<int<0, 1000>>
     */
    #[Requires('count($amounts) <= 10')]
    #[Ensures('count($result) === count($amounts) + 1')]
    public static function buildsTheListWithASpread(array $amounts): array
    {
        $copy = [0];
        foreach ($amounts as $amount) {
            $copy = [...$copy, $amount];
        }
        return $copy;
    }
    /**
     * @param list<int<0, 1000>> $amounts
     * @return list<int<0, 1000>>
     */
    #[Requires('count($amounts) <= 3')]
    #[Ensures('count($result) <= count($amounts)')]
    public static function claimsAFilterThatAppendsTwice(array $amounts): array
    {
        $kept = [];
        foreach ($amounts as $amount) {
            $kept[] = $amount;
            $kept[] = $amount;
        }
        return $kept;
    }
    /**
     * @param list<int<0, 1000>> $amounts
     */
    #[Requires('count($amounts) <= 10')]
    #[Ensures('$result === 2 * count($amounts)')]
    public static function appendsToTheListItIterates(array $amounts): int
    {
        $work = [...$amounts];
        foreach ($work as $amount) {
            $work[] = $amount;
        }
        return count($work);
    }
    /**
     * @param list<int<0, 1000>> $amounts
     */
    #[Requires('count($amounts) <= 10')]
    #[Ensures('$result >= 0')]
    public static function appendsToTheParameterItIterates(array $amounts): int
    {
        foreach ($amounts as $amount) {
            $amounts[] = $amount;
        }
        return count($amounts);
    }
}
