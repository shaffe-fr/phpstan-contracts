<?php

declare(strict_types=1);

namespace PhpstanContracts\Tests\Fixtures;

use PhpstanContracts\Attribute\Ensures;
use PhpstanContracts\Attribute\Pure;
use PhpstanContracts\Attribute\Requires;

/**
 * in_array() over list variables, === on lists, and lists and shapes passed to and from calls.
 */
final class ListComparisonFixture
{
    /**
     * @param list<string> $blockedSkus
     */
    #[Ensures('implies(count($blockedSkus) === 0, !$result)')]
    public static function isBlocked(array $blockedSkus, string $sku): bool
    {
        return in_array($sku, $blockedSkus, true);
    }

    /**
     * @param list<string> $blockedSkus
     */
    #[Ensures('implies(count($blockedSkus) === 0, $result)')]
    public static function isBlockedButWrongWay(array $blockedSkus, string $sku): bool
    {
        return in_array($sku, $blockedSkus, true);
    }

    /**
     * @param list<int<0, 100>> $items
     */
    #[Ensures('implies($result, $n >= 0 && $n <= 100)')]
    public static function aFoundNeedleIsInTheElementRange(array $items, int $n): bool
    {
        return in_array($n, $items, true);
    }

    /**
     * @param list<int> $items
     */
    #[Ensures('!$result')]
    public static function claimsAnIntIsNeverFound(array $items, int $n): bool
    {
        return in_array($n, $items, true);
    }

    /**
     * @param list<int> $items
     */
    #[Requires('count($items) > 0')]
    #[Ensures('$result')]
    public static function theFirstElementIsFound(array $items): bool
    {
        return in_array($items[0], $items, true);
    }

    /**
     * @param list<int> $items
     */
    #[Ensures('$result === in_array($n, $items)')]
    public static function looseOverInts(array $items, int $n): bool
    {
        return in_array($n, $items);
    }

    /**
     * @param list<bool> $flags
     */
    #[Ensures('implies(count($flags) === 0, !$result)')]
    public static function looseOverBools(array $flags, bool $flag): bool
    {
        return in_array($flag, $flags);
    }

    /**
     * @param list<Suit> $suits
     */
    #[Ensures('implies($result, count($suits) > 0)')]
    public static function overEnumCases(array $suits, Suit $suit): bool
    {
        return in_array($suit, $suits);
    }

    /**
     * @param list<?int> $items
     */
    #[Ensures('implies(count($items) === 0, !$result)')]
    public static function aNullNeedleOverNullableInts(array $items): bool
    {
        return in_array(null, $items, true);
    }

    /**
     * @param list<?int> $items
     */
    #[Ensures('implies($result, count($items) > 0)')]
    public static function anIntNeedleOverNullableInts(array $items, int $n): bool
    {
        return in_array($n, $items, true);
    }

    /**
     * @param list<int> $items
     */
    #[Ensures('implies($result, count($items) > 0)')]
    public static function aNullableNeedleOverInts(array $items, ?int $n): bool
    {
        return in_array($n, $items, true);
    }

    /**
     * @param list<?int> $items
     */
    #[Ensures('$result === ($items[0] === null)')]
    #[Requires('count($items) === 1')]
    public static function aNullNeedleFindsANull(array $items): bool
    {
        return in_array(null, $items, true);
    }

    /**
     * NAN is never found, even in a list that holds it.
     */
    #[Ensures('!$result')]
    public static function nanIsNeverFound(float $x): bool
    {
        $items = [$x, NAN];

        return in_array(NAN, $items, true);
    }

    /**
     * -0.0 equals 0.0 and the other way round, with or without strict.
     */
    #[Ensures('$result')]
    public static function negativeZeroIsFoundAsZero(): bool
    {
        $items = [0.0, 1.5];

        return in_array(-0.0, $items, true) && in_array(-0.0, $items);
    }

    #[Ensures('$result === ($x === $y || $x === 2.5)')]
    public static function floatsAreComparedLikeIeee(float $x, float $y): bool
    {
        $items = [$y, 2.5];

        return in_array($x, $items, true);
    }

    #[Ensures('$result === ($x === $y)')]
    public static function floatsMissTheSecondValue(float $x, float $y): bool
    {
        $items = [$y, 2.5];

        return in_array($x, $items, true);
    }

    #[Ensures('!$result')]
    public static function anEmptyListVariableContainsNothing(int $n): bool
    {
        $items = [];

        return in_array($n, $items, true);
    }

    #[Ensures('$result === ($n === $a || $n === $b)')]
    public static function aBuiltList(int $n, int $a, int $b): bool
    {
        $items = [$a];
        $items[] = $b;

        return in_array($n, $items, true);
    }

    #[Ensures('implies($result, count($holder->values) > 0)')]
    public static function overAProperty(ListHolder $holder, int $n): bool
    {
        return in_array($n, $holder->values, true);
    }

    /**
     * @param list<int> $items
     */
    #[Ensures('implies($result, $n === 7 || count($items) > 0)')]
    public static function overACallResult(array $items, int $n): bool
    {
        return in_array($n, self::withSeven($items), true);
    }

    /**
     * @param list<int> $items
     * @return list<int>
     */
    private static function withSeven(array $items): array
    {
        return [...$items, 7];
    }

    /**
     * @param list<int> $items
     * @return list<int>
     */
    #[Ensures('implies(in_array(7, $result, true), count($result) > 0)')]
    #[Ensures('in_array(7, $result, true)')]
    public static function inAContractOverTheResult(array $items): array
    {
        return [...$items, 7];
    }

    /**
     * @param list<int> $items
     * @return list<int>
     */
    #[Ensures('!in_array(8, $result, true)')]
    public static function negatedInAContract(array $items): array
    {
        return [...$items, 7];
    }

    /**
     * @param list<int> $items
     */
    #[Pure]
    public static function aNeedleOfAnotherType(array $items, float $x): bool
    {
        return in_array($x, $items, true);
    }

    /**
     * @param list<int> $items
     */
    #[Pure]
    public static function aFloatNeedleWithoutStrict(array $items, float $x): bool
    {
        return in_array($x, $items);
    }

    /**
     * @param list<string> $items
     */
    #[Pure]
    public static function stringsWithoutStrict(array $items, string $s): bool
    {
        return in_array($s, $items);
    }

    /**
     * @param list<?int> $items
     */
    #[Pure]
    public static function nullableElementsWithoutStrict(array $items, int $n): bool
    {
        return in_array($n, $items);
    }

    /**
     * @param array{net: int, vat: int} $totals
     */
    #[Pure]
    public static function overAShape(array $totals, int $n): bool
    {
        return in_array($n, $totals, true);
    }

    /**
     * @param list<PriceContext> $items
     */
    #[Pure]
    public static function overAListOfObjects(array $items, PriceContext $item): bool
    {
        return in_array($item, $items, true);
    }

    /**
     * @param list<array{net: int}> $items
     * @param array{net: int} $item
     */
    #[Pure]
    public static function overAListOfShapes(array $items, array $item): bool
    {
        return in_array($item, $items, true);
    }

    /**
     * @param list<int>|null $items
     */
    #[Pure]
    public static function overANullableList(?array $items, int $n): bool
    {
        return in_array($n, $items, true);
    }

    /**
     * @param list<int> $first
     * @param list<int> $second
     */
    #[Ensures('implies($result, count($first) === count($second))')]
    public static function comparesListsOfInts(array $first, array $second): bool
    {
        return $first === $second;
    }

    /**
     * @param list<int> $first
     * @param list<int> $second
     */
    #[Ensures('implies(count($first) === count($second), $result)')]
    public static function sameCountIsNotIdentity(array $first, array $second): bool
    {
        return $first === $second;
    }

    /**
     * @param list<int> $first
     * @param list<int> $second
     */
    #[Requires('count($first) === 1 && count($second) === 1')]
    #[Ensures('$result === ($first[0] === $second[0])')]
    public static function oneElementEach(array $first, array $second): bool
    {
        return $first === $second;
    }

    /**
     * @param list<int> $first
     * @param list<int> $second
     */
    #[Requires('count($first) === 1 && count($second) === 1')]
    #[Ensures('$result === ($first[0] !== $second[0])')]
    public static function notIdentical(array $first, array $second): bool
    {
        return $first !== $second;
    }

    /**
     * @param list<int> $items
     */
    #[Ensures('$result')]
    public static function aListIsIdenticalToItself(array $items): bool
    {
        return $items === $items;
    }

    /**
     * @param list<string> $first
     * @param list<string> $second
     */
    #[Ensures('implies($result, count($first) === count($second))')]
    public static function comparesListsOfStrings(array $first, array $second): bool
    {
        return $first === $second;
    }

    /**
     * @param list<?int> $first
     * @param list<?int> $second
     */
    #[Ensures('implies($result, count($first) === count($second))')]
    public static function comparesListsOfNullableInts(array $first, array $second): bool
    {
        return $first === $second;
    }

    /**
     * @param list<bool> $first
     * @param list<bool> $second
     */
    #[Ensures('implies($result, count($first) === count($second))')]
    public static function comparesListsOfBools(array $first, array $second): bool
    {
        return $first === $second;
    }

    /**
     * @param list<Suit> $first
     * @param list<Suit> $second
     */
    #[Ensures('implies($result, count($first) === count($second))')]
    public static function comparesListsOfEnumCases(array $first, array $second): bool
    {
        return $first === $second;
    }

    /**
     * @param list<int> $items
     */
    #[Ensures('$result === (count($items) === 0)')]
    public static function comparesToTheEmptyList(array $items): bool
    {
        return $items === [];
    }

    /**
     * @param list<int> $items
     */
    #[Ensures('$result === (count($items) > 0)')]
    public static function differsFromTheEmptyList(array $items): bool
    {
        return [] !== $items;
    }

    /**
     * @param list<int> $items
     */
    #[Ensures('$result === (count($items) === 2 && $items[0] === 1 && $items[1] === 2)')]
    public static function comparesToALiteral(array $items): bool
    {
        return $items === [1, 2];
    }

    /**
     * @param list<int> $items
     */
    #[Ensures('$result === (count($items) === 0 ? 0 : (count($items) === 1 && $items[0] === 1 ? 1 : 2))')]
    public static function matchesOnAList(array $items): int
    {
        return match ($items) {
            [] => 0,
            [1] => 1,
            default => 2,
        };
    }

    /**
     * @param list<int> $items
     */
    #[Ensures('$result === 0')]
    public static function matchesOnAListWrongly(array $items): int
    {
        return match ($items) {
            [] => 0,
            [1] => 1,
            default => 2,
        };
    }

    #[Ensures('$result')]
    public static function twoEmptyListsAreIdentical(): bool
    {
        $first = [];
        $second = [];

        return $first === $second;
    }

    #[Pure]
    public static function comparesListsOfFloats(float $x, float $y): bool
    {
        $first = [$x];
        $second = [$y];

        return $first === $second;
    }

    #[Pure]
    public static function comparesListsOfNullableFloats(?float $x, ?float $y): bool
    {
        $first = [$x];
        $second = [$y];

        return $first === $second;
    }

    /**
     * @param list<PriceContext> $first
     * @param list<PriceContext> $second
     */
    #[Pure]
    public static function comparesListsOfObjects(array $first, array $second): bool
    {
        return $first === $second;
    }

    /**
     * @param list<array{net: int}> $first
     * @param list<array{net: int}> $second
     */
    #[Pure]
    public static function comparesListsOfShapes(array $first, array $second): bool
    {
        return $first === $second;
    }

    /**
     * @param array{net: int} $first
     * @param array{net: int} $second
     */
    #[Pure]
    public static function comparesShapes(array $first, array $second): bool
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
    public static function comparesListsWithNotEqual(array $first, array $second): bool
    {
        return $first != $second;
    }

    /**
     * @param list<int> $first
     * @param list<int> $second
     */
    #[Pure]
    public static function comparesListsWithTheDiamond(array $first, array $second): bool
    {
        return $first <> $second;
    }

    /**
     * @param list<int> $first
     * @param list<int> $second
     */
    #[Pure]
    public static function ordersLists(array $first, array $second): bool
    {
        return $first <= $second;
    }

    /**
     * @param list<int> $first
     */
    #[Pure]
    public static function comparesListsOfDifferentElements(array $first, float $x): bool
    {
        $second = [$x];

        return $first === $second;
    }

    /**
     * @param list<int> $items
     */
    #[Pure]
    public static function comparesAListWithAnInt(array $items, int $n): bool
    {
        return $items === $n;
    }

    /**
     * @param list<int> $items
     */
    #[Pure]
    public static function comparesAListWithNull(array $items): bool
    {
        return $items === null;
    }

    /**
     * @param list<int>|null $items
     * @param list<int> $other
     */
    #[Pure]
    public static function comparesANullableList(?array $items, array $other): bool
    {
        return $items === $other;
    }

    /**
     * @param list<int> $items
     */
    #[Ensures('$result === count($items)')]
    public static function callsWithAList(array $items): int
    {
        return self::sizeOf($items);
    }

    /**
     * @param list<int> $items
     */
    private static function sizeOf(array $items): int
    {
        return count($items);
    }

    #[Ensures('$result === 0')]
    public static function callsWithAnEmptyList(): int
    {
        return self::sizeOf([]);
    }

    #[Ensures('$result === 2')]
    public static function callsWithABuiltList(int $a): int
    {
        $items = [$a];
        $items[] = $a;

        return self::sizeOf($items);
    }

    #[Ensures('$result === 5')]
    public static function callsWithTheWrongCount(): int
    {
        return self::sizeOf([1, 2]);
    }

    /**
     * @param array{net: int<0, 1000>, vat: int<0, 1000>} $totals
     */
    #[Ensures('$result === $totals["net"] + $totals["vat"]')]
    public static function callsWithAShape(array $totals): int
    {
        return self::sumOf($totals);
    }

    /**
     * @param array{vat: int<0, 1000>, net: int<0, 1000>} $totals
     */
    private static function sumOf(array $totals): int
    {
        return $totals['net'] + $totals['vat'];
    }

    #[Ensures('$result === 3')]
    public static function callsWithAShapeLiteral(): int
    {
        return self::sumOf(['vat' => 1, 'net' => 2]);
    }

    /**
     * @return array{net: int, vat: int}
     */
    private static function split(int $net): array
    {
        return ['net' => $net, 'vat' => 0];
    }

    #[Ensures('$result === $net')]
    public static function readsAShapeReturnedByACall(int $net): int
    {
        return self::split($net)['net'];
    }

    /**
     * @param list<int> $items
     * @return list<int>
     */
    private static function same(array $items): array
    {
        return $items;
    }

    /**
     * @param list<int> $items
     * @return list<int>
     */
    #[Ensures('$result === $items')]
    public static function returnsAListReturnedByACall(array $items): array
    {
        return self::same($items);
    }

    /**
     * @param list<float> $rates
     */
    private static function countRates(array $rates): int
    {
        return count($rates);
    }

    #[Pure]
    public static function aListOfIntsForAListOfFloats(int $n): int
    {
        return self::countRates([$n]);
    }

    #[Ensures('$result === 2')]
    public static function aListOfFloatsForAListOfFloats(float $x): int
    {
        return self::countRates([$x, 1.5]);
    }

    /**
     * @param list<int> $items
     * @return list<int>
     */
    private static function takesNullable(?array $items): array
    {
        return $items ?? [];
    }

    /**
     * @return list<int>
     */
    #[Ensures('count($result) === 0')]
    public static function passesNullForANullableList(): array
    {
        return self::takesNullable(null);
    }

    /**
     * @param list<int> $items
     */
    #[Ensures('$result === count($items)')]
    public static function passesAListForANullableList(array $items): int
    {
        return count(self::takesNullable($items));
    }

    /**
     * @param list<int<0, 100>> $values
     */
    #[Ensures('$result === count($values)')]
    public static function buildsADataClassWithAList(array $values): int
    {
        return count((new ListBox($values, ['net' => 1, 'vat' => 2]))->values);
    }

    /**
     * @param list<int<0, 100>> $values
     */
    #[Requires('count($values) > 0')]
    #[Ensures('$result >= 0 && $result <= 100')]
    public static function readsAnElementOfADataClassList(array $values): int
    {
        return (new ListBox($values, ['net' => 1, 'vat' => 2]))->values[0];
    }

    /**
     * @param array{net: int, vat: int} $totals
     */
    #[Ensures('$result === $totals["vat"]')]
    public static function buildsADataClassWithAShape(array $totals): int
    {
        return (new ListBox([], $totals))->totals['vat'];
    }

    #[Ensures('$result === 2')]
    public static function buildsADataClassWithAListLiteral(): int
    {
        return count((new ListBox([1, 2], ['net' => 1, 'vat' => 2]))->values);
    }

    #[Ensures('$result === 3')]
    public static function readsAShapeLiteralInADataClass(): int
    {
        return (new ListBox([], ['vat' => 1, 'net' => 2]))->totals['net'] + (new ListBox([], ['vat' => 1, 'net' => 2]))->totals['vat'];
    }

    #[Ensures('$result === 4')]
    public static function readsAShapeLiteralInADataClassWrongly(): int
    {
        return (new ListBox([], ['vat' => 1, 'net' => 2]))->totals['net'] + (new ListBox([], ['vat' => 1, 'net' => 2]))->totals['vat'];
    }
}

final class ListBox
{
    /**
     * @param list<int<0, 100>> $values
     * @param array{net: int<0, 1000>, vat: int<0, 1000>} $totals
     */
    public function __construct(
        public readonly array $values,
        public readonly array $totals,
    ) {
    }
}
