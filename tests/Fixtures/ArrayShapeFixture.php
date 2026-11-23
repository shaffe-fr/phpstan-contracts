<?php

declare(strict_types=1);

namespace PhpstanContracts\Tests\Fixtures;

use PhpstanContracts\Attribute\Ensures;
use PhpstanContracts\Attribute\Pure;
use PhpstanContracts\Attribute\Requires;

/**
 * Array shapes with string keys: what is accepted, and each way to leave the subset.
 */
final class ArrayShapeFixture
{
    /**
     * @param int<0, 1000000> $gross
     * @return array{net: int<0, 1000000>, vat: int<0, 1000000>}
     */
    #[Ensures('$result["net"] + $result["vat"] === $gross')]
    public static function splitsAnAmount(int $gross): array
    {
        $vat = intdiv($gross, 5);

        return ['net' => $gross - $vat, 'vat' => $vat];
    }

    /**
     * @param int<0, 1000000> $gross
     * @return array{net: int<0, 1000000>, vat: int<0, 1000000>}
     */
    #[Ensures('$result["net"] + $result["vat"] === $gross')]
    public static function losesACent(int $gross): array
    {
        return ['net' => $gross, 'vat' => 1];
    }

    /**
     * @param array{total: int, label: string} $order
     */
    #[Ensures('$result === $order["total"]')]
    public static function readsAField(array $order): int
    {
        return $order['total'];
    }

    /**
     * @param array{total: int, label: string} $order
     */
    #[Requires('$order["total"] > 0')]
    #[Ensures('$result > 0')]
    public static function aRequiresClauseReadsAField(array $order): int
    {
        return $order['total'];
    }

    /**
     * The keys of a literal can be written in any order.
     *
     * @return array{net: int, vat: int}
     */
    #[Ensures('$result["net"] === 1 && $result["vat"] === 2')]
    public static function literalInAnotherKeyOrder(): array
    {
        return ['vat' => 2, 'net' => 1];
    }

    /**
     * @return array{net: int, vat: int}
     */
    #[Ensures('$result["net"] === $net && $result["vat"] === $vat')]
    public static function builtFromParameters(int $net, int $vat): array
    {
        return ['net' => $net, 'vat' => $vat];
    }

    /**
     * @return array{note: ?string}
     */
    #[Ensures('$result["note"] === null')]
    public static function nullInANullableField(): array
    {
        return ['note' => null];
    }

    /**
     * @return array{note: ?string}
     */
    #[Ensures('$result["note"] === $text')]
    public static function valueInANullableField(string $text): array
    {
        return ['note' => $text];
    }

    /**
     * @param array{note: ?string} $entry
     */
    #[Ensures('$result === "none" || $result === $entry["note"]')]
    public static function coalescesAField(array $entry): string
    {
        return $entry['note'] ?? 'none';
    }

    /**
     * @param array{note: ?string} $entry
     */
    #[Ensures('$result === ($entry["note"] !== null)')]
    public static function issetOnAField(array $entry): bool
    {
        return isset($entry['note']);
    }

    /**
     * @param array{net: int}|null $totals
     */
    #[Ensures('implies($totals === null, $result === 0)')]
    public static function narrowedByANullCheck(?array $totals): int
    {
        return $totals === null ? 0 : $totals['net'];
    }

    /**
     * @param array{net: int, vat: int} $totals
     * @return array{net: int, vat: int}
     */
    #[Ensures('$result["net"] === $totals["vat"] && $result["vat"] === $totals["net"]')]
    public static function swapsTheFields(array $totals): array
    {
        return ['net' => $totals['vat'], 'vat' => $totals['net']];
    }

    #[Ensures('$result === 1')]
    public static function callsAMethodWithAShape(): int
    {
        return self::netOf(['net' => 1, 'vat' => 2]);
    }

    /**
     * @param array{net: int, vat: int} $totals
     */
    private static function netOf(array $totals): int
    {
        return $totals['net'];
    }

    #[Ensures('$result === 7')]
    public static function callsAMethodWithAnotherKeyOrder(): int
    {
        return self::vatOf(['net' => 1, 'vat' => 7]);
    }

    /**
     * @param array{vat: int, net: int} $totals
     */
    private static function vatOf(array $totals): int
    {
        return $totals['vat'];
    }

    #[Ensures('$result === $holder->totals["net"]')]
    public static function readsAShapeProperty(ShapeHolder $holder): int
    {
        return $holder->totals['net'];
    }

    /**
     * @param int<0, 100> $net
     * @return array{net: int<0, 50>}
     */
    #[Pure]
    public static function aFieldRangeIsAGoal(int $net): array
    {
        return ['net' => $net];
    }

    /**
     * @param array{net: int<0, 100>} $totals
     */
    #[Ensures('$result >= 0 && $result <= 200')]
    public static function aFieldRangeIsAnAssumption(array $totals): int
    {
        return $totals['net'] * 2;
    }

    /**
     * @param array{net: int, vat: int} $totals
     */
    #[Pure]
    public static function overflowsOnAField(array $totals): int
    {
        return $totals['net'] + $totals['vat'];
    }

    /**
     * @param array{net: int, vat: int} $totals
     */
    #[Pure]
    public static function readsAnUnknownKey(array $totals): int
    {
        return $totals['gross'];
    }

    /**
     * @param array{net: int, vat: int} $totals
     */
    #[Pure]
    public static function readsWithAVariableKey(array $totals, string $key): int
    {
        return $totals[$key];
    }

    /**
     * @param array{net: int, vat: int} $totals
     */
    #[Pure]
    public static function readsWithAnIntegerKey(array $totals): int
    {
        return $totals[0];
    }

    /**
     * @param array{net: int, vat: int} $totals
     */
    #[Pure]
    public static function readsAsAProperty(array $totals): int
    {
        return $totals->net;
    }

    #[Pure]
    public static function readsAnOffsetOfAString(string $text): string
    {
        return $text[0];
    }

    /**
     * @param array{net: int}|null $totals
     */
    #[Pure]
    public static function readsAnOptionalShapeWithoutACheck(?array $totals): int
    {
        return $totals['net'];
    }

    /**
     * @param array{net: int} $first
     * @param array{net: int} $second
     */
    #[Pure]
    public static function comparesShapesStrictly(array $first, array $second): bool
    {
        return $first === $second;
    }

    /**
     * @param array{net: int} $first
     * @param array{net: int} $second
     */
    #[Pure]
    public static function comparesShapesLoosely(array $first, array $second): bool
    {
        return $first == $second;
    }

    /**
     * @param array{net: int} $first
     * @param array{net: int} $second
     */
    #[Pure]
    public static function ordersShapes(array $first, array $second): bool
    {
        return $first < $second;
    }

    /**
     * @param array{net: int} $totals
     */
    #[Pure]
    public static function matchesAShape(array $totals): int
    {
        return match ($totals) {
            ['net' => 1] => 1,
            default => 0,
        };
    }

    /**
     * @param array{net: int, vat: int} $totals
     */
    #[Pure]
    public static function countsAShape(array $totals): int
    {
        return count($totals);
    }

    /**
     * @param array{net: int, vat: int} $totals
     */
    #[Pure]
    public static function searchesAShape(array $totals, int $amount): bool
    {
        return in_array($amount, $totals, true);
    }

    /**
     * @param array{net: int, vat: int} $totals
     */
    #[Pure]
    public static function usesAnArrayFunction(array $totals): int
    {
        return array_sum($totals);
    }

    /**
     * @param array{net: int, vat: int} $totals
     */
    #[Pure]
    public static function writesAKey(array $totals): int
    {
        $totals['net'] = 1;

        return 0;
    }

    /**
     * @param array{net: int, vat: int} $totals
     */
    #[Pure]
    public static function addsToAKey(array $totals): int
    {
        $totals['net'] += 1;

        return 0;
    }

    /**
     * @param array{net: int, vat: int} $totals
     */
    #[Pure]
    public static function appendsToAShape(array $totals): int
    {
        $totals[] = 1;

        return 0;
    }

    /**
     * @param array{net: int, vat: int} $totals
     */
    #[Pure]
    public static function writesANestedKey(array $totals): int
    {
        $totals['net']['x'] = 1;

        return 0;
    }

    /**
     * @param array{net: int, vat: int} $totals
     */
    #[Pure]
    public static function destructuresAShape(array $totals): int
    {
        [$first, $second] = $totals;

        return 0;
    }

    #[Pure]
    public static function unsetsAKey(int $net): int
    {
        $totals = ['net' => $net];
        unset($totals['net']);

        return 0;
    }

    #[Pure]
    public static function aListLiteral(int $net): int
    {
        $list = [$net, 2];

        return 0;
    }

    #[Pure]
    public static function aLiteralMixingKeysAndNoKeys(int $net): int
    {
        $mixed = [1, 'net' => $net];

        return 0;
    }

    #[Pure]
    public static function aLiteralWithAnIntegerKey(int $net): int
    {
        $keyed = [0 => $net];

        return 0;
    }

    #[Pure]
    public static function aLiteralWithAnIntegerLikeKey(int $net): int
    {
        $keyed = ['1' => $net];

        return 0;
    }

    #[Pure]
    public static function aLiteralWithAVariableKey(string $key): int
    {
        $keyed = [$key => 1];

        return 0;
    }

    #[Pure]
    public static function aLiteralWithADuplicateKey(int $net): int
    {
        $keyed = ['net' => $net, 'net' => 2];

        return 0;
    }

    #[Pure]
    public static function aLiteralWithASpread(int $net): int
    {
        $other = ['net' => $net];
        $keyed = [...$other];

        return 0;
    }

    #[Pure]
    public static function aLiteralWithAReference(int $net): int
    {
        $keyed = ['net' => &$net];

        return 0;
    }

    #[Pure]
    public static function aVariableHoldingNull(): int
    {
        $keyed = ['note' => null];

        return 0;
    }

    #[Pure]
    public static function aVariableThatChangesItsShape(string $text): int
    {
        $entry = ['note' => $text];
        $entry = ['note' => null];

        return 0;
    }

    /**
     * @return array{note: ?string}
     */
    #[Pure]
    public static function aNullInsideChosenBranches(bool $condition): array
    {
        return $condition ? ['note' => null] : ['note' => null];
    }

    /**
     * @param array{note: ?string} $entry
     * @return array{note: ?string}
     */
    #[Ensures('$result["note"] === null')]
    public static function assignsANullToATypedVariable(array $entry): array
    {
        $copy = $entry;
        $copy = ['note' => null];

        return $copy;
    }

    /**
     * @param array{net: int, vat?: int} $totals
     */
    #[Pure]
    public static function anOptionalKey(array $totals): int
    {
        return $totals['net'];
    }

    /**
     * @param array{int, string} $pair
     */
    #[Pure]
    public static function aTuple(array $pair): int
    {
        return 1;
    }

    /**
     * @param array{} $nothing
     */
    #[Pure]
    public static function anEmptyShape(array $nothing): int
    {
        return 1;
    }

    /**
     * @param array<string, int> $amounts
     */
    #[Pure]
    public static function arbitraryKeys(array $amounts): int
    {
        return 1;
    }

    /**
     * @param array{net: int}|array{vat: int} $either
     */
    #[Pure]
    public static function aUnionOfShapes(array $either): int
    {
        return 1;
    }

    /**
     * @param array{net: float|string} $totals
     */
    #[Pure]
    public static function aUnionFieldType(array $totals): int
    {
        return 1;
    }
}
