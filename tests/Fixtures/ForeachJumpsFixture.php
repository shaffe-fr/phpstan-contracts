<?php

declare(strict_types=1);

namespace PhpstanContracts\Tests\Fixtures;

use PhpstanContracts\Attribute\Ensures;
use PhpstanContracts\Attribute\Pure;
use PhpstanContracts\Attribute\Requires;
use PhpstanContracts\Attribute\Throws;

/**
 * `break`, `continue`, an early `return` and a `throw` inside a `foreach`, nested loops and a
 * callee with a loop.
 */
final class ForeachJumpsFixture
{
    /**
     * Adds the amounts up to the first large one, which is included.
     *
     * @param list<int<0, 1000>> $amounts
     */
    #[Requires('count($amounts) <= 10')]
    #[Ensures('$result >= 0 && $result <= 1000 * count($amounts)')]
    #[Ensures('implies(count($amounts) > 0 && $amounts[0] > 500, $result === $amounts[0])')]
    public static function stopsAtTheFirstLargeAmount(array $amounts): int
    {
        $total = 0;
        foreach ($amounts as $amount) {
            $total += $amount;
            if ($amount > 500) {
                break;
            }
        }

        return $total;
    }

    /**
     * The `break` comes one element too early: the large amount is left out.
     *
     * @param list<int<0, 1000>> $amounts
     */
    #[Requires('count($amounts) <= 10')]
    #[Ensures('implies(count($amounts) > 0 && $amounts[0] > 500, $result === $amounts[0])')]
    public static function stopsBeforeTheFirstLargeAmount(array $amounts): int
    {
        $total = 0;
        foreach ($amounts as $amount) {
            if ($amount > 500) {
                break;
            }
            $total += $amount;
        }

        return $total;
    }

    /**
     * @param list<int<0, 1000>> $amounts
     */
    #[Requires('count($amounts) <= 10')]
    #[Ensures('$result >= 0 && $result <= 1000 * count($amounts)')]
    #[Ensures('implies(count($amounts) === 2, $result === ($amounts[0] <= 100 ? 0 : $amounts[0]) + ($amounts[1] <= 100 ? 0 : $amounts[1]))')]
    public static function skipsTheSmallAmounts(array $amounts): int
    {
        $total = 0;
        foreach ($amounts as $amount) {
            if ($amount <= 100) {
                continue;
            }
            $total += $amount;
        }

        return $total;
    }

    /**
     * The `continue` skips the large amounts, so the claim that they are all counted fails.
     *
     * @param list<int<0, 1000>> $amounts
     */
    #[Requires('count($amounts) <= 10')]
    #[Ensures('$result === count($amounts)')]
    public static function countsWhatItDoesNotSkip(array $amounts): int
    {
        $counted = 0;
        foreach ($amounts as $amount) {
            if ($amount > 500) {
                continue;
            }
            $counted += 1;
        }

        return $counted;
    }

    /**
     * `continue` and `break` in the same body, in an `elseif` chain.
     *
     * @param list<int<-1000, 1000>> $amounts
     */
    #[Requires('count($amounts) <= 10')]
    #[Ensures('$result >= 0 && $result <= 1000 * count($amounts)')]
    public static function sumsThePositiveAmountsUntilAZero(array $amounts): int
    {
        $total = 0;
        foreach ($amounts as $amount) {
            if ($amount < 0) {
                continue;
            } elseif ($amount === 0) {
                break;
            }
            $total += $amount;
        }

        return $total;
    }

    /**
     * A variable set before the `break` keeps its value after the loop.
     *
     * @param list<int<0, 1000>> $amounts
     */
    #[Requires('count($amounts) <= 10')]
    #[Ensures('$result >= -1 && $result < count($amounts)')]
    public static function keepsTheKeyWhereItStopped(array $amounts): int
    {
        $found = -1;
        foreach ($amounts as $index => $amount) {
            if ($amount > 500) {
                $found = $index;
                break;
            }
        }

        return $found;
    }

    /**
     * @param list<int<0, 1000>> $amounts
     */
    #[Requires('count($amounts) <= 10')]
    #[Ensures('$result >= -1 && $result < count($amounts)')]
    #[Ensures('implies($result >= 0, $amounts[$result] > $limit)')]
    #[Ensures('implies($result >= 1, $amounts[0] <= $limit)')]
    public static function indexOfTheFirstAmountAbove(array $amounts, int $limit): int
    {
        foreach ($amounts as $index => $amount) {
            if ($amount > $limit) {
                return $index;
            }
        }

        return -1;
    }

    /**
     * Returns the key of the first amount that is at least the limit, which is not what it claims.
     *
     * @param list<int<0, 1000>> $amounts
     */
    #[Requires('count($amounts) <= 10')]
    #[Ensures('implies($result >= 0, $amounts[$result] > $limit)')]
    public static function returnsTheWrongElement(array $amounts, int $limit): int
    {
        foreach ($amounts as $index => $amount) {
            if ($amount >= $limit) {
                return $index;
            }
        }

        return -1;
    }

    /**
     * @param list<int<0, 1000>> $quantities
     */
    #[Requires('count($quantities) <= 10')]
    #[Throws(\InvalidArgumentException::class, when: 'in_array(0, $quantities, true)')]
    public static function throwsAtTheFirstZero(array $quantities): int
    {
        $total = 0;
        foreach ($quantities as $quantity) {
            if ($quantity === 0) {
                throw new \InvalidArgumentException('A quantity is zero.');
            }
            $total += $quantity;
        }

        return $total;
    }

    /**
     * Never throws for the first element, so `[0]` returns although the declaration says it throws.
     *
     * @param list<int<0, 1000>> $quantities
     */
    #[Requires('count($quantities) <= 10')]
    #[Throws(\InvalidArgumentException::class, when: 'in_array(0, $quantities, true)')]
    public static function throwsOnlyFromTheSecondElement(array $quantities): int
    {
        $total = 0;
        foreach ($quantities as $index => $quantity) {
            if ($quantity === 0 && $index > 0) {
                throw new \InvalidArgumentException('A quantity is zero.');
            }
            $total += $quantity;
        }

        return $total;
    }

    /**
     * Nothing declares the exception, so the throw of an iteration after the first is unexpected.
     *
     * @param list<int<0, 1000>> $quantities
     */
    #[Requires('count($quantities) <= 10')]
    public static function throwsWithoutDeclaringIt(array $quantities): int
    {
        $total = 0;
        foreach ($quantities as $index => $quantity) {
            if ($quantity === 0 && $index > 0) {
                throw new \InvalidArgumentException('A quantity is zero.');
            }
            $total += $quantity;
        }

        return $total;
    }

    /**
     * @param list<int<0, 1000>> $rows
     * @param list<int<0, 1000>> $columns
     */
    #[Requires('count($rows) <= 5')]
    #[Requires('count($columns) <= 5')]
    #[Ensures('$result >= 0 && $result <= 30 * 1000')]
    public static function addsEveryPair(array $rows, array $columns): int
    {
        $total = 0;
        foreach ($rows as $row) {
            foreach ($columns as $column) {
                $total += $column;
            }
            $total += $row;
        }

        return $total;
    }

    /**
     * `continue 2` ends the iteration of the outer loop, so the outer `+= 1` runs once per row
     * that has no large column, and the inner one stops at the first.
     *
     * @param list<int<0, 1000>> $rows
     * @param list<int<0, 1000>> $columns
     */
    #[Requires('count($rows) <= 4')]
    #[Requires('count($columns) <= 4')]
    #[Ensures('$result >= 0 && $result <= count($rows)')]
    public static function countsTheRowsWithoutALargeColumn(array $rows, array $columns): int
    {
        $count = 0;
        foreach ($rows as $row) {
            foreach ($columns as $column) {
                if ($column > $row) {
                    continue 2;
                }
            }
            $count += 1;
        }

        return $count;
    }

    /**
     * `break 2` leaves both loops: the first pair in reading order decides, as 10 * row key + column key.
     *
     * @param list<int<0, 1000>> $rows
     * @param list<int<0, 1000>> $columns
     */
    #[Requires('count($rows) <= 4')]
    #[Requires('count($columns) <= 4')]
    #[Ensures('$result >= -1 && $result <= 33')]
    #[Ensures('implies(count($rows) === 2 && count($columns) === 2 && $rows[0] === $columns[1] && $rows[0] !== $columns[0] && $rows[1] === $columns[0], $result === 1)')]
    public static function takesTheFirstMatchingPair(array $rows, array $columns): int
    {
        $found = -1;
        foreach ($rows as $i => $row) {
            foreach ($columns as $j => $column) {
                if ($row === $column) {
                    $found = 10 * $i + $j;
                    break 2;
                }
            }
        }

        return $found;
    }

    /**
     * The `break` only leaves the inner loop, so the outer one can still find a later pair: the
     * claim that the first row decides fails.
     *
     * @param list<int<0, 1000>> $rows
     * @param list<int<0, 1000>> $columns
     */
    #[Requires('count($rows) <= 4')]
    #[Requires('count($columns) <= 4')]
    #[Ensures('implies(count($rows) > 0 && $result > 0, $result === $rows[0])')]
    public static function breaksOnlyTheInnerLoop(array $rows, array $columns): int
    {
        $found = 0;
        foreach ($rows as $row) {
            foreach ($columns as $column) {
                if ($row === $column) {
                    $found = $row;
                    break;
                }
            }
        }

        return $found;
    }

    /**
     * The overflow is in the second loop of the nest, in its second iteration, within the second
     * iteration of the first.
     *
     * @param list<int> $rows
     * @param list<int> $columns
     */
    #[Requires('count($rows) <= 3')]
    #[Requires('count($columns) <= 3')]
    public static function overflowsInTheInnerLoop(array $rows, array $columns): int
    {
        $total = 0;
        foreach ($rows as $row) {
            foreach ($columns as $column) {
                $total += $column;
            }
        }

        return $total;
    }

    /**
     * @param list<int<0, 100>> $rows
     * @param list<int<0, 100>> $columns
     */
    #[Requires('count($rows) <= 20')]
    #[Requires('count($columns) <= 20')]
    public static function nestsPastTheBudget(array $rows, array $columns): int
    {
        $total = 0;
        foreach ($rows as $row) {
            foreach ($columns as $column) {
                $total += $row + $column;
            }
        }

        return $total;
    }

    /**
     * @param list<int<0, 1000>> $amounts
     * @param list<int<0, 1000>> $weights
     */
    #[Requires('count($amounts) <= 3')]
    #[Requires('count($weights) <= 3')]
    #[Ensures('$result >= 0 && $result <= 9 * 1000')]
    public static function callsAHelperThatLoopsInsideALoop(array $amounts, array $weights): int
    {
        $total = 0;
        foreach ($amounts as $amount) {
            $total += self::sumOf($weights);
        }

        return $total;
    }

    /**
     * @param list<int<0, 1000>> $amounts
     */
    #[Requires('count($amounts) <= 5')]
    #[Ensures('$result >= 0 && $result <= 5000')]
    public static function callsAHelperThatLoops(array $amounts): int
    {
        return self::sumOf($amounts);
    }

    /**
     * The caller does not bound the list, and the callee unrolls only what its own precondition says.
     *
     * @param list<int<0, 1000>> $amounts
     */
    #[Pure]
    public static function callsAHelperWithAnUnboundedList(array $amounts): int
    {
        return self::sumOf($amounts);
    }

    /**
     * @param list<int<0, 1000>> $amounts
     */
    #[Requires('count($amounts) <= 5')]
    private static function sumOf(array $amounts): int
    {
        $total = 0;
        foreach ($amounts as $amount) {
            $total += $amount;
        }

        return $total;
    }
}
