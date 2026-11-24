<?php

declare(strict_types=1);

namespace PhpstanContracts\Tests\Fixtures;

use PhpstanContracts\Attribute\Ensures;
use PhpstanContracts\Attribute\Pure;
use PhpstanContracts\Attribute\Requires;

/**
 * Lists without loops: what is accepted, and each way to leave the subset.
 */
final class ListFixture
{
    /**
     * @param list<int<0, 10000000>> $amounts
     */
    #[Ensures('$result >= 0')]
    public static function readsAGuardedElement(array $amounts, int $index): int
    {
        if ($index >= 0 && $index < count($amounts)) {
            return $amounts[$index];
        }

        return 0;
    }

    /**
     * @param list<int<0, 10000000>> $amounts
     */
    #[Ensures('$result >= 0')]
    public static function readsAfterAnEarlyReturn(array $amounts, int $index): int
    {
        if ($index < 0 || $index >= count($amounts)) {
            return 0;
        }

        return $amounts[$index];
    }

    /**
     * @param non-empty-list<int<0, 10000000>> $amounts
     */
    #[Ensures('$result === $amounts[count($amounts) - 1]')]
    public static function readsTheLastElement(array $amounts): int
    {
        return $amounts[count($amounts) - 1];
    }

    /**
     * @param list<int> $items
     * @return list<int>
     */
    #[Ensures('count($result) === count($items) + ($flag ? 1 : 0)')]
    public static function appendsInABranch(array $items, bool $flag): array
    {
        $list = [...$items];
        if ($flag) {
            $list[] = 1;
        }
        return $list;
    }

    /**
     * @param list<int<0, 10000000>> $amounts
     */
    #[Ensures('$result >= 0')]
    public static function readsWithAWrongGuard(array $amounts, int $index): int
    {
        if ($index >= 0 && $index <= count($amounts)) {
            return $amounts[$index];
        }

        return 0;
    }

    /**
     * @param list<int<0, 10000000>> $amounts
     */
    #[Pure]
    public static function readsWithoutAGuard(array $amounts): int
    {
        return $amounts[0];
    }

    /**
     * @param non-empty-list<int<0, 10000000>> $amounts
     */
    #[Ensures('$result >= 0')]
    public static function readsTheFirstOfANonEmptyList(array $amounts): int
    {
        return $amounts[0];
    }

    /**
     * @return non-empty-list<int>
     */
    #[Pure]
    public static function returnsEmptyForANonEmptyList(): array
    {
        return [];
    }

    /**
     * @param list<int<0, 100>> $items
     * @param int<0, 100> $item
     * @return list<int<0, 100>>
     */
    #[Ensures('count($result) === count($items) + 1')]
    public static function appendsAValidValue(array $items, int $item): array
    {
        $list = [...$items];
        $list[] = $item;
        return $list;
    }

    /**
     * @param list<int<0, 100>> $items
     * @return list<int<0, 100>>
     */
    #[Pure]
    public static function appendsAnUncheckedValue(array $items, int $item): array
    {
        $list = [...$items];
        $list[] = $item;
        return $list;
    }

    /**
     * @param list<int> $items
     */
    #[Ensures('$result === count($items)')]
    public static function countsTheItems(array $items): int
    {
        return count($items);
    }

    /**
     * @param list<int> $items
     */
    #[Ensures('$result === count($items)')]
    public static function countsOneTooMany(array $items): int
    {
        return count($items) + 1;
    }

    /**
     * @param list<?int> $items
     */
    #[Ensures('implies($result, $index >= 0 && $index < count($items))')]
    public static function issetOnAList(array $items, int $index): bool
    {
        return isset($items[$index]);
    }

    /**
     * @param list<?int> $items
     */
    #[Ensures('implies($result, $items[$index] !== null)')]
    public static function issetImpliesANonNullElement(array $items, int $index): bool
    {
        return isset($items[$index]);
    }

    /**
     * @param list<?int> $items
     */
    #[Ensures('implies($index >= 0 && $index < count($items), $result)')]
    public static function issetOnANullableListWhenTheKeyExists(array $items, int $index): bool
    {
        return isset($items[$index]);
    }

    /**
     * @param list<?int> $items
     */
    #[Ensures('implies($index >= 0 && $index < count($items) && $items[$index] !== null, $result === $items[$index])')]
    public static function coalescesAnElement(array $items, int $index): int
    {
        return $items[$index] ?? 0;
    }

    /**
     * @param list<?int> $items
     */
    #[Ensures('implies($index >= 0 && $index < count($items), $result === $items[$index])')]
    public static function coalescesAnElementThatIsNull(array $items, int $index): int
    {
        return $items[$index] ?? 0;
    }

    /**
     * @param list<int> $items
     */
    #[Ensures('$result === (isset($items[$index]) ? $items[$index] : -1)')]
    public static function coalescesAnElementOfAPlainList(array $items, int $index): int
    {
        return $items[$index] ?? -1;
    }

    /**
     * @param list<int> $items
     */
    #[Ensures('count($items) === 0 || $result === $items[0]')]
    public static function coalescesToAnotherElementWithAGuard(array $items): int
    {
        return $items[0] ?? 0;
    }

    /**
     * @return list<int>
     */
    #[Ensures('count($result) === 3 && $result[0] === 1 && $result[2] === 3')]
    public static function aListLiteral(): array
    {
        return [1, 2, 3];
    }

    /**
     * @return list<?int>
     */
    #[Ensures('count($result) === 2 && $result[0] === 1 && $result[1] === null')]
    public static function aListLiteralWithNull(): array
    {
        return [1, null];
    }

    /**
     * @return list<string>
     */
    #[Ensures('count($result) === 2 && $result[0] === $a && $result[1] === "b"')]
    public static function aListLiteralOfExpressions(string $a): array
    {
        return [$a, 'b'];
    }

    /**
     * @return list<int>
     */
    #[Ensures('count($result) === 0')]
    public static function anEmptyListLiteral(): array
    {
        return [];
    }

    /**
     * @return list<int>
     */
    #[Ensures('count($result) === 2 && $result[0] === $a && $result[1] === $b')]
    public static function buildsAListByAppending(int $a, int $b): array
    {
        $list = [];
        $list[] = $a;
        $list[] = $b;

        return $list;
    }

    /**
     * @return list<int>
     */
    #[Ensures('count($result) === 1 && $result[0] === $item')]
    public static function appendingLeavesTheOtherCopyAlone(int $item): array
    {
        $first = [$item];
        $second = $first;
        $second[] = 2;
        return $first;
    }

    /**
     * @return list<int>
     */
    #[Ensures('count($result) === 3')]
    public static function appendsToAMergeOfBuiltLists(bool $flag): array
    {
        $list = $flag ? [1, 2] : [3, 4];
        $list[] = 5;
        return $list;
    }

    /**
     * @param list<int> $items
     * @return list<int>
     */
    #[Ensures('count($result) === count($items) + 1 && $result[count($items)] === $item')]
    public static function appendsToAParameter(array $items, int $item): array
    {
        $items[] = $item;
        return $items;
    }

    /**
     * @param list<int> $items
     */
    #[Pure]
    public static function appendsToACopyOfAParameter(array $items): int
    {
        $copy = $items;
        $copy[] = 1;
        return 0;
    }

    #[Pure]
    public static function appendsToAListFromAProperty(ListHolder $holder): int
    {
        $values = $holder->values;
        $values[] = 1;
        return 0;
    }

    /**
     * @param list<int> $items
     */
    #[Pure]
    public static function appendsToAMergeWithAParameter(array $items, bool $flag): int
    {
        $list = $flag ? $items : [];
        $list[] = 1;
        return 0;
    }

    /**
     * @param list<int> $items
     * @return list<int>
     */
    #[Ensures('count($result) === count($items) + 1 && $result[count($items)] === $item')]
    public static function spreadsAnItemAfterTheList(array $items, int $item): array
    {
        return [...$items, $item];
    }

    /**
     * @param list<int> $items
     * @return list<int>
     */
    #[Ensures('count($result) === count($items) + 1 && $result[0] === $item && implies($i >= 0 && $i < count($items), $result[$i + 1] === $items[$i])')]
    public static function spreadsAnItemBeforeTheList(array $items, int $item, int $i): array
    {
        return [$item, ...$items];
    }

    /**
     * @param list<int> $first
     * @param list<int> $second
     * @return list<int>
     */
    #[Ensures('count($result) === count($first) + count($second) && implies($i >= 0 && $i < count($second), $result[count($first) + $i] === $second[$i]) && implies($i >= 0 && $i < count($first), $result[$i] === $first[$i])')]
    public static function concatenatesTwoLists(array $first, array $second, int $i): array
    {
        return [...$first, ...$second];
    }

    /**
     * @param list<int> $items
     * @return list<int>
     */
    #[Ensures('count($result) === count($items)')]
    public static function spreadsOneTooMany(array $items, int $item): array
    {
        return [...$items, $item];
    }

    /**
     * @param list<int<0, 100>> $items
     * @param int<0, 100> $item
     * @return list<int<0, 100>>
     */
    #[Ensures('count($result) === count($items) + 1')]
    public static function spreadsAValidValue(array $items, int $item): array
    {
        return [...$items, $item];
    }

    /**
     * @param list<int<0, 100>> $items
     * @return list<int<0, 100>>
     */
    #[Pure]
    public static function spreadsAnUncheckedValue(array $items, int $item): array
    {
        return [...$items, $item];
    }

    /**
     * @param list<int> $items
     * @return list<int>
     */
    #[Ensures('count($result) === count($items) + 1')]
    public static function appendsAfterASpreadAndLeavesTheParameterAlone(array $items, int $item): array
    {
        $copy = [...$items];
        $copy[] = $item;
        return $copy;
    }

    /**
     * @param list<int> $items
     * @return list<int>
     */
    #[Ensures('count($result) === count($items)')]
    public static function appendsToASingleSpreadCopy(array $items): array
    {
        $copy = [...$items];
        $items[] = 1;
        return $copy;
    }

    /**
     * @param array{net: int, vat: int} $shape
     */
    #[Pure]
    public static function spreadsAShape(array $shape): int
    {
        $list = [...$shape];
        return 0;
    }

    #[Pure]
    public static function spreadsAnInt(int $n): int
    {
        $list = [...$n];
        return 0;
    }

    /**
     * @param list<int>|null $items
     */
    #[Pure]
    public static function spreadsANullableList(?array $items): int
    {
        $list = [...$items];
        return 0;
    }

    /**
     * @param list<int> $items
     */
    #[Pure]
    public static function spreadsWithAKey(array $items): int
    {
        $list = ['a' => 1, ...$items];
        return 0;
    }

    /**
     * @param list<string> $items
     */
    #[Pure]
    public static function spreadsAStringAmongInts(array $items): int
    {
        $list = [...$items, 1];
        return 0;
    }

    #[Pure]
    public static function spreadsOnlyAnEmptyList(): int
    {
        $list = [...[]];
        return 0;
    }

    /**
     * @param array<string, int> $items
     */
    #[Pure]
    public static function spreadsAnAssociativeArray(array $items): int
    {
        $list = [...$items];
        return 0;
    }

    /**
     * @return list<int>
     */
    #[Ensures('count($result) <= 1')]
    public static function mergesAnEmptyListWithAnotherList(bool $flag): array
    {
        if ($flag) {
            $list = [];
        } else {
            $list = [1];
        }

        return $list;
    }

    /**
     * @return list<int>
     */
    #[Ensures('count($result) === ($flag ? 0 : 1)')]
    public static function aTernaryWithAnEmptyList(bool $flag): array
    {
        return $flag ? [] : [1];
    }

    /**
     * @param list<int>|null $items
     * @return list<int>
     */
    #[Ensures('$items === null ? count($result) === 0 : count($result) === count($items)')]
    public static function coalescesToAnEmptyList(?array $items): array
    {
        return $items ?? [];
    }

    /**
     * @param list<int> $items
     * @return list<int>
     */
    #[Ensures('count($result) === 1')]
    public static function startsOverWithAnEmptyList(array $items): array
    {
        $list = $items;
        $list = [];
        $list[] = 1;

        return $list;
    }

    /**
     * @param list<int> $items
     */
    #[Requires('count($items) > 0')]
    #[Pure]
    public static function aPreconditionGuardsTheRead(array $items): int
    {
        return $items[0];
    }

    /**
     * @param list<int> $items
     */
    #[Requires('$items[0] > 0')]
    #[Pure]
    public static function aPreconditionReadsWithoutAGuard(array $items): int
    {
        return $items[0];
    }

    /**
     * @param list<int> $items
     */
    #[Requires('count($items) > 0 && $items[0] > 0')]
    #[Ensures('$result > 0')]
    public static function aPreconditionReadsAfterItsGuard(array $items): int
    {
        return $items[0];
    }

    /**
     * @param list<int> $items
     * @return list<int>
     */
    #[Ensures('$result[0] === 1')]
    public static function anEnsuresReadsAnElementTheResultMayLack(array $items): array
    {
        return $items;
    }

    /**
     * @param non-empty-list<int> $items
     * @return non-empty-list<int>
     */
    #[Ensures('$result[0] === $items[0]')]
    public static function anEnsuresReadsAnElementOfANonEmptyResult(array $items): array
    {
        return $items;
    }

    /**
     * @param list<int<0, 1000>> $items
     */
    #[Requires('count($items) >= 2')]
    #[Ensures('$result >= 0 && $result <= 2000')]
    public static function elementRangesAreAssumed(array $items): int
    {
        return $items[0] + $items[1];
    }

    /**
     * @param list<int> $items
     */
    #[Requires('count($items) >= 2')]
    #[Pure]
    public static function unboundedElementsCanOverflow(array $items): int
    {
        return $items[0] + $items[1];
    }

    /**
     * @param list<int> $items
     */
    #[Pure]
    public static function callsAMethodThatReads(array $items): int
    {
        return self::firstOf($items);
    }

    /**
     * @param list<int> $items
     */
    private static function firstOf(array $items): int
    {
        return $items[0];
    }

    /**
     * @param list<int> $items
     */
    #[Requires('count($items) > 0')]
    #[Pure]
    public static function callsAMethodThatReadsAfterAGuard(array $items): int
    {
        return self::firstOf($items);
    }

    #[Pure]
    public static function callsAMethodWithALiteral(): int
    {
        return self::firstOf([4, 5]);
    }

    #[Pure]
    public static function callsAMethodWithAnEmptyList(): int
    {
        return self::firstOf([]);
    }

    #[Ensures('$result === count($holder->values)')]
    public static function readsAListProperty(ListHolder $holder): int
    {
        return count($holder->values);
    }

    /**
     * @param array{items: list<int>, label: string} $order
     */
    #[Requires('count($order["items"]) > 0')]
    #[Ensures('$result === $order["items"][0]')]
    public static function readsAListInAShape(array $order): int
    {
        return $order['items'][0];
    }

    /**
     * @param list<array{net: int, vat: int}> $lines
     */
    #[Requires('count($lines) > 0')]
    #[Ensures('$result === $lines[0]["net"]')]
    public static function readsAShapeInAList(array $lines): int
    {
        return $lines[0]['net'];
    }

    /**
     * @param list<Suit> $suits
     */
    #[Requires('count($suits) > 0')]
    #[Ensures('$result === ($suits[0] === Suit::Hearts)')]
    public static function readsAnEnumInAList(array $suits): bool
    {
        return $suits[0] === Suit::Hearts;
    }

    /**
     * @param list<string> $names
     */
    #[Requires('count($names) > 0')]
    #[Ensures('$result === strlen($names[0])')]
    public static function readsAStringInAList(array $names): int
    {
        return strlen($names[0]);
    }

    /**
     * @return list<float>
     */
    #[Ensures('count($result) === 2 && is_nan($result[1])')]
    public static function buildsAListOfFloats(): array
    {
        return [1.5, NAN];
    }

    /**
     * @param list<int> $items
     */
    #[Pure]
    public static function readsWithAStringIndex(array $items, string $index): int
    {
        return $items[$index];
    }

    /**
     * @param list<int> $items
     */
    #[Pure]
    public static function readsWithAFloatIndex(array $items): int
    {
        return $items[1.5];
    }

    /**
     * @param list<int> $items
     */
    #[Pure]
    public static function readsWithANullableIndex(array $items, ?int $index): int
    {
        return $items[$index];
    }

    /**
     * @param list<float|string> $items
     */
    #[Pure]
    public static function aUnionElementType(array $items): int
    {
        return 1;
    }

    /**
     * @param list<list<int>> $rows
     */
    #[Pure]
    public static function aListOfLists(array $rows): int
    {
        return 1;
    }

    /**
     * @param list<float> $rates
     */
    #[Pure]
    public static function aListOfFloatsAsAnInput(array $rates): int
    {
        return 1;
    }

    /**
     * @param list<?float> $rates
     */
    #[Pure]
    public static function aListOfNullableFloatsAsAnInput(array $rates): int
    {
        return 1;
    }

    /**
     * @param non-empty-array<int, int> $items
     */
    #[Pure]
    public static function aNonEmptyArrayWithArbitraryKeys(array $items): int
    {
        return 1;
    }

    /**
     * @param list<int> $items
     */
    #[Pure]
    public static function writesAnElement(array $items): int
    {
        $items[0] = 1;

        return 0;
    }

    /**
     * @param list<int> $items
     */
    #[Pure]
    public static function addsToAnElement(array $items): int
    {
        $items[0] += 1;

        return 0;
    }

    /**
     * @param list<int> $items
     */
    #[Pure]
    public static function writesANestedKey(array $items): int
    {
        $items[0][1] = 1;

        return 0;
    }

    /**
     * @param list<int> $items
     */
    #[Pure]
    public static function appendsToANestedKey(array $items): int
    {
        $items[0][] = 1;

        return 0;
    }

    #[Pure]
    public static function appendsToAnUndefinedVariable(int $n): int
    {
        $missing[] = $n;

        return 0;
    }

    #[Pure]
    public static function appendsADifferentType(int $n): int
    {
        $rates = [1.5];
        $rates[] = $n;

        return 0;
    }

    #[Pure]
    public static function appendsToAPropertyOfAnObject(ListHolder $holder): int
    {
        $holder->values[] = 1;

        return 0;
    }

    #[Pure]
    public static function appendsToAnInt(int $n): int
    {
        $n[] = 1;

        return 0;
    }

    /**
     * @param list<int>|null $items
     */
    #[Pure]
    public static function appendsToANullableList(?array $items): int
    {
        $items[] = 1;

        return 0;
    }

    #[Pure]
    public static function appendsANullToAnEmptyList(): int
    {
        $items = [];
        $items[] = null;

        return 0;
    }

    /**
     * @param list<int> $items
     */
    #[Pure]
    public static function destructuresAList(array $items): int
    {
        [$first, $second] = $items;

        return 0;
    }

    /**
     * @param list<int> $items
     */
    #[Pure]
    public static function unsetsAnElement(array $items): int
    {
        unset($items[0]);

        return 0;
    }

    /**
     * @param list<int> $items
     */
    #[Pure]
    public static function loopsOverAList(array $items): int
    {
        foreach ($items as $item) {
            return $item;
        }

        return 0;
    }

    #[Pure]
    public static function mixesKeysAndNoKeys(): int
    {
        $list = [1, 'a' => 2];

        return 0;
    }

    #[Pure]
    public static function aLiteralOfDifferentTypes(): int
    {
        $list = [1, 'a'];

        return 0;
    }

    #[Pure]
    public static function aLiteralOfAnIntAndAFloat(): int
    {
        $list = [1, 2.0];

        return 0;
    }

    #[Pure]
    public static function aNestedListLiteral(): int
    {
        $list = [[1], [2]];

        return 0;
    }

    #[Pure]
    public static function aLiteralOfOnlyNulls(): int
    {
        $list = [null, null];

        return 0;
    }

    #[Pure]
    public static function readsFromAnEmptyListLiteral(): int
    {
        return [][0];
    }

    #[Pure]
    public static function readsAnOffsetOfAString(string $text): string
    {
        return $text[0];
    }

    /**
     * @param list<int> $items
     */
    #[Pure]
    public static function emptyOnAList(array $items): bool
    {
        return empty($items);
    }

    /**
     * @param list<int> $items
     */
    #[Pure]
    public static function aListAsACondition(array $items): int
    {
        if ($items) {
            return 1;
        }

        return 0;
    }

    /**
     * @param list<int> $items
     */
    #[Pure]
    public static function countRecursive(array $items): int
    {
        return count($items, COUNT_RECURSIVE);
    }

    #[Pure]
    public static function countOfAnInt(int $n): int
    {
        return count($n);
    }

    /**
     * @param list<int>|null $items
     */
    #[Pure]
    public static function countOfANullableList(?array $items): int
    {
        return count($items);
    }

    /**
     * @param list<int> $items
     */
    #[Pure]
    public static function anotherArrayFunction(array $items): int
    {
        return array_product($items);
    }

    /**
     * @param list<int> $first
     * @param list<int> $second
     */
    #[Pure]
    public static function comparesListsStrictly(array $first, array $second): bool
    {
        return $first === $second;
    }

    /**
     * @param list<int> $first
     * @param list<int> $second
     */
    #[Pure]
    public static function comparesListsLoosely(array $first, array $second): bool
    {
        return $first == $second;
    }

    /**
     * @param list<int> $first
     * @param list<int> $second
     */
    #[Pure]
    public static function ordersLists(array $first, array $second): bool
    {
        return $first < $second;
    }

    /**
     * @param list<int> $items
     */
    #[Pure]
    public static function searchesAListVariable(array $items, int $n): bool
    {
        return in_array($n, $items, true);
    }

    /**
     * @param list<int> $items
     */
    #[Pure]
    public static function issetWithAStringIndex(array $items): bool
    {
        return isset($items['a']);
    }

    /**
     * @param list<int> $items
     */
    #[Pure]
    public static function coalescesWithTheWrongDefaultType(array $items): int
    {
        return $items[0] ?? 'none';
    }
    /**
     * @param list<array{net: int<0, 100>}> $lines
     */
    #[Pure]
    public static function coalescesAShapeKeyOfAListElement(array $lines, int $i): int
    {
        return $lines[$i]['net'] ?? -1;
    }

    /**
     * @param list<array{net: int<0, 100>}> $lines
     */
    #[Pure]
    public static function issetsAShapeKeyOfAListElement(array $lines, int $i): bool
    {
        return isset($lines[$i]['net']);
    }

    /**
     * @param list<PriceContext> $items
     */
    #[Pure]
    public static function coalescesAPropertyOfAListElement(array $items, int $i): int
    {
        return $items[$i]->quantity ?? -1;
    }

    /**
     * @param list<PriceContext> $items
     */
    #[Pure]
    public static function issetsAPropertyOfAListElement(array $items, int $i): bool
    {
        return isset($items[$i]->quantity);
    }
}
