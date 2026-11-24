<?php
declare(strict_types=1);
namespace PhpstanContracts\Tests\Fixtures;
use PhpstanContracts\Attribute\Ensures;
use PhpstanContracts\Attribute\Requires;
/**
 * Loops whose `#[Ensures]` pin the result exactly, with a contract that PHP can evaluate.
 *
 * ForeachRuleTest proves every method here. LoopSemanticsTest runs the same methods in PHP on
 * the lists that a list<int<0, 100>> allows, up to the bound of the `#[Requires]`, and evaluates
 * their `#[Ensures]` on what PHP returns. A translation that reads a loop differently from PHP
 * fails one of the two: either the proof is refuted, or PHP falsifies the contract that was proven.
 *
 * Every method takes `$items` as its only parameter, a list<int<0, 100>> of at most 3 elements.
 */
final class ForeachSemantics
{
    /**
     * PHP iterates the list as it was on entry: the elements appended inside the loop are not visited.
     *
     * @param list<int<0, 100>> $items
     * @return list<int<0, 200>>
     */
    #[Requires('count($items) <= 3')]
    #[Ensures('count($result) === 2 * count($items)')]
    #[Ensures('count($items) === 0 || ($result[count($items)] === 2 * $items[0] && $result[0] === $items[0])')]
    #[Ensures('count($items) < 3 || $result[5] === 2 * $items[2]')]
    public static function appendsWhileIterating(array $items): array
    {
        $work = [...$items];
        foreach ($work as $item) {
            $work[] = 2 * $item;
        }
        return $work;
    }
    /**
     * The same, written as a spread, and the variable is a list built here from the start.
     *
     * @param list<int<0, 100>> $items
     * @return list<int<0, 100>>
     */
    #[Requires('count($items) <= 3')]
    #[Ensures('count($result) === 2 * count($items)')]
    #[Ensures('count($items) === 0 || $result[count($items)] === $items[0]')]
    public static function doublesTheListWithASpread(array $items): array
    {
        $work = [...$items];
        foreach ($work as $item) {
            $work = [...$work, $item];
        }
        return $work;
    }
    /**
     * Emptying the variable inside the loop does not stop the iteration over the elements it held.
     *
     * @param list<int<0, 100>> $items
     */
    #[Requires('count($items) <= 3')]
    #[Ensures('$result === count($items)')]
    public static function visitsEveryElementAfterEmptyingTheVariable(array $items): int
    {
        $work = [...$items];
        $visited = 0;
        foreach ($work as $item) {
            $work = [];
            $visited += 1;
        }
        return $visited;
    }
    /**
     * @param list<int<0, 100>> $items
     */
    #[Requires('count($items) <= 3')]
    #[Ensures('$result === 0')]
    public static function leavesTheVariableEmptied(array $items): int
    {
        $work = [...$items];
        foreach ($work as $item) {
            $work = [];
        }
        return count($work);
    }
    /**
     * Reassigning the iterated variable to another list does not change what is iterated either.
     *
     * @param list<int<0, 100>> $items
     */
    #[Requires('count($items) <= 3')]
    #[Ensures('$result === (count($items) > 0 ? $items[0] : 0) + (count($items) > 1 ? $items[1] : 0) + (count($items) > 2 ? $items[2] : 0)')]
    public static function sumsTheListItWasGivenAndNotTheOneItBecame(array $items): int
    {
        $work = [...$items];
        $total = 0;
        foreach ($work as $item) {
            $work = [7, 7, 7, 7];
            $total += $item;
        }
        return $total;
    }
    /**
     * After the loop, the key and the value are the ones of the last iteration, or are untouched.
     *
     * @param list<int<0, 100>> $items
     */
    #[Requires('count($items) <= 3')]
    #[Ensures('count($items) === 0 ? $result === -1001 : $result === (count($items) - 1) * 1000 + $items[count($items) - 1]')]
    public static function keepsTheLastKeyAndValue(array $items): int
    {
        $key = -1;
        $value = -1;
        foreach ($items as $index => $item) {
            $key = $index;
            $value = $item;
        }
        return $key * 1000 + $value;
    }
    /**
     * The key and the value are real variables: a loop that writes them leaves them written.
     *
     * @param list<int<0, 100>> $items
     */
    #[Requires('count($items) <= 3')]
    #[Ensures('$result === count($items) * 7')]
    public static function overwritesTheKeyAndTheValueInTheBody(array $items): int
    {
        $total = 0;
        foreach ($items as $index => $item) {
            $index = 3;
            $item = 4;
            $total += $index + $item;
        }
        return $total;
    }
    /**
     * `break` leaves the loop at the first element above 50.
     *
     * @param list<int<0, 100>> $items
     */
    #[Requires('count($items) <= 3')]
    #[Ensures('$result === (count($items) > 0 && $items[0] > 50 ? 0 : (count($items) > 1 && $items[1] > 50 ? 1 : (count($items) > 2 && $items[2] > 50 ? 2 : -1)))')]
    public static function findsTheFirstElementAbove50(array $items): int
    {
        $found = -1;
        foreach ($items as $index => $item) {
            if ($item > 50) {
                $found = $index;
                break;
            }
        }
        return $found;
    }
    /**
     * `continue` skips the rest of the iteration for a zero.
     *
     * @param list<int<0, 100>> $items
     */
    #[Requires('count($items) <= 3')]
    #[Ensures('$result === (count($items) > 0 && $items[0] !== 0 ? 1 : 0) + (count($items) > 1 && $items[1] !== 0 ? 1 : 0) + (count($items) > 2 && $items[2] !== 0 ? 1 : 0)')]
    public static function countsTheElementsThatAreNotZero(array $items): int
    {
        $count = 0;
        foreach ($items as $item) {
            if ($item === 0) {
                continue;
            }
            $count += 1;
        }
        return $count;
    }
    /**
     * `break` and `continue` together, and a variable that only some iterations write.
     *
     * @param list<int<0, 100>> $items
     */
    #[Requires('count($items) <= 3')]
    #[Ensures('$result === (count($items) > 0 && $items[0] <= 50 ? $items[0] : 0) + (count($items) > 0 && $items[0] <= 50 && count($items) > 1 && $items[1] <= 50 ? $items[1] : 0) + (count($items) > 0 && $items[0] <= 50 && count($items) > 1 && $items[1] <= 50 && count($items) > 2 && $items[2] <= 50 ? $items[2] : 0)')]
    public static function sumsTheElementsBeforeTheFirstOneAbove50(array $items): int
    {
        $sum = 0;
        foreach ($items as $item) {
            if ($item > 50) {
                break;
            }
            $sum += $item;
        }
        return $sum;
    }
    /**
     * Returning from inside the body ends the method at the first match.
     *
     * @param list<int<0, 100>> $items
     */
    #[Requires('count($items) <= 3')]
    #[Ensures('$result === (count($items) > 0 && $items[0] % 2 === 0 ? $items[0] : (count($items) > 1 && $items[1] % 2 === 0 ? $items[1] : (count($items) > 2 && $items[2] % 2 === 0 ? $items[2] : -1)))')]
    public static function returnsTheFirstEvenElement(array $items): int
    {
        foreach ($items as $item) {
            if ($item % 2 === 0) {
                return $item;
            }
        }
        return -1;
    }
    /**
     * A filter builds a list of the elements above 50, in order.
     *
     * @param list<int<0, 100>> $items
     * @return list<int<51, 100>>
     */
    #[Requires('count($items) <= 3')]
    #[Ensures('count($result) === (count($items) > 0 && $items[0] > 50 ? 1 : 0) + (count($items) > 1 && $items[1] > 50 ? 1 : 0) + (count($items) > 2 && $items[2] > 50 ? 1 : 0)')]
    #[Ensures('count($result) === 0 || $result[0] === (count($items) > 0 && $items[0] > 50 ? $items[0] : (count($items) > 1 && $items[1] > 50 ? $items[1] : $items[2]))')]
    public static function keepsTheElementsAbove50(array $items): array
    {
        $kept = [];
        foreach ($items as $item) {
            if ($item > 50) {
                $kept[] = $item;
            }
        }
        return $kept;
    }
    /**
     * An empty list runs the body zero times: the variables keep the value they had before.
     *
     * @param list<int<0, 100>> $items
     */
    #[Requires('count($items) <= 3')]
    #[Ensures('count($items) > 0 || $result === 41')]
    #[Ensures('count($items) === 0 || $result === 41 + count($items)')]
    public static function leavesTheVariablesAloneForAnEmptyList(array $items): int
    {
        $total = 41;
        foreach ($items as $item) {
            $total += 1;
        }
        return $total;
    }
}
