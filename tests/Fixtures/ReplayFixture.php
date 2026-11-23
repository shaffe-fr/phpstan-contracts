<?php

declare(strict_types=1);

namespace PhpstanContracts\Tests\Fixtures;

use PhpstanContracts\Attribute\Ensures;
use PhpstanContracts\Attribute\Pure;
use PhpstanContracts\Attribute\Requires;
use PhpstanContracts\Attribute\Throws;

/**
 * Every public method here is refuted; the private ones are helpers that they
 * call. The replay test runs each public method in PHP with the values of the
 * reported counterexample and checks that PHP agrees with Z3.
 */
final class ReplayFixture
{
    /**
     * @param int<0, 100000000> $totalItems
     * @param int<1, 1000> $perPage
     */
    #[Ensures('$totalItems === 0 || ($result - 1) * $perPage < $totalItems')]
    public static function pageCount(int $totalItems, int $perPage): int
    {
        return intdiv($totalItems, $perPage) + 1;
    }

    /**
     * @param int<-1000000000, 1000000000> $net
     * @param int<0, 3000> $rate
     */
    #[Ensures('abs($result * 10000 - $net * $rate) <= 5000')]
    public static function vat(int $net, int $rate): int
    {
        return intdiv($net * $rate + 5000, 10000);
    }

    /**
     * @param int<0, 100> $discount
     * @param int<0, 1000> $nights
     */
    #[Ensures('$result >= 0')]
    public static function negativeTotal(int $discount, int $nights): int
    {
        return $nights * 10 - ($discount + 5) * $nights;
    }

    #[Ensures('$result >= 0.0')]
    public static function absoluteValue(float $x): float
    {
        return abs($x);
    }

    #[Ensures('$result === $a + ($b + $c)')]
    public static function regrouped(float $a, float $b, float $c): float
    {
        return ($a + $b) + $c;
    }

    #[Ensures('is_finite($result)')]
    public static function midpoint(float $low, float $high): float
    {
        return ($low + $high) * 0.5;
    }

    #[Ensures('implies($result, str_ends_with($file, ".png") || str_ends_with($file, ".jpg"))')]
    public static function allowedImage(string $file): bool
    {
        return str_contains($file, '.png') || str_contains($file, '.jpg');
    }

    #[Ensures('!str_starts_with($result, "/")')]
    public static function storageKey(string $tenant, string $name): string
    {
        return $tenant . '/' . $name;
    }

    #[Ensures('strlen($result) === strlen($a)')]
    public static function concatenation(string $a, string $b): string
    {
        return $a . $b;
    }

    /** @param int<1, 1000>|null $limit */
    #[Ensures('$result >= 1 && $result <= 100')]
    public static function forgottenClamp(?int $limit): int
    {
        return $limit ?? 20;
    }

    #[Ensures('$result === ($a ?? 0) + 1')]
    public static function nullableArithmetic(?bool $flag, ?int $a): int
    {
        return $a === null ? 1 : $a + 2;
    }

    #[Ensures('$result === $left')]
    public static function booleanLogic(bool $left, bool $right): bool
    {
        return $left && $right;
    }

    /** @param int<0, 20> $n */
    #[Ensures('$result >= 0')]
    public static function minimumThenSubtraction(int $n): int
    {
        return min($n, 10) - 5;
    }

    #[Ensures('$result === 0')]
    public static function moduloKeepsTheSignOfTheDividend(int $a): int
    {
        return $a % 3;
    }

    #[Ensures('$result === 0')]
    public static function truncatedQuotient(int $a): int
    {
        return intdiv($a, 3);
    }

    #[Ensures('$result === 1999')]
    public static function priceToCents(): int
    {
        return (int) floor(19.99 * 100.0);
    }

    #[Ensures('$result > 0')]
    public static function enumValue(Level $level): int
    {
        return $level->value - 1;
    }

    #[Pure]
    public static function overflow(int $a, int $b): int
    {
        return $a * $b;
    }

    /** @param int<0, 1000> $days */
    #[Ensures('strlen($result) === 7')]
    public static function delayLabel(int $days): string
    {
        return $days . ' jours';
    }

    #[Ensures('$result === ($n === 1)')]
    public static function inArrayWithoutStrict(int $n): bool
    {
        return in_array($n, [1, 2.0]);
    }

    #[Pure]
    public static function overflowInACall(int $x): int
    {
        return self::twice($x);
    }

    /** The argument is the caller's parameter of the same name, so the replay can evaluate the precondition. */
    #[Pure]
    public static function brokenPreconditionOfACall(int $x): int
    {
        return self::halve($x);
    }

    #[Pure]
    public static function undeclaredThrow(int $n): int
    {
        if ($n > 10) {
            throw new \LogicException('Too large.');
        }

        return $n;
    }

    #[Throws(\InvalidArgumentException::class, when: '$x < 0')]
    #[Throws(\RangeException::class, when: '$x < 10')]
    public static function anotherClassThrownUnderACondition(int $x): int
    {
        if ($x < 10) {
            throw new \RangeException('Small.');
        }

        return $x;
    }

    #[Throws(\InvalidArgumentException::class, when: '$n < 0 || $n > 100')]
    public static function guardThatLetsTheLastValueThrough(int $n): int
    {
        if ($n < 0 || $n > 101) {
            throw new \InvalidArgumentException('Out of range.');
        }

        return $n;
    }

    /**
     * @param int<0, 1000> $gross
     * @return array{net: int, vat: int}
     */
    #[Ensures('$result["net"] + $result["vat"] === $gross')]
    public static function shapeThatLosesACent(int $gross): array
    {
        return ['net' => $gross, 'vat' => 1];
    }

    /**
     * The type declares total before label, the literal writes label first.
     *
     * @param array{total: int<0, 100>, label: string} $order
     * @return array{total: int<0, 101>, label: string}
     */
    #[Ensures('$result["total"] === $order["total"] + 1')]
    public static function shapeInAnotherKeyOrder(array $order): array
    {
        return ['label' => $order['label'], 'total' => $order['total']];
    }

    /**
     * @param array{note: ?string} $entry
     */
    #[Ensures('$result !== "none"')]
    public static function nullableFieldOfAShape(array $entry): string
    {
        return $entry['note'] ?? 'none';
    }

    /**
     * @param array{net: int, vat: int} $totals
     */
    #[Pure]
    public static function overflowOnShapeFields(array $totals): int
    {
        return $totals['net'] + $totals['vat'];
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
     * @param list<int> $items
     */
    #[Pure]
    public static function readsTheFirstElementWithoutAGuard(array $items): int
    {
        return $items[0];
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
     * @param list<int> $items
     * @return list<int>
     */
    #[Ensures('count($result) === count($items) + 2')]
    public static function appendsOnlyOnce(array $items, int $item): array
    {
        return [...$items, $item];
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
     * @param list<?int> $items
     */
    #[Ensures('implies($index >= 0 && $index < count($items), $result)')]
    public static function issetIsFalseForANullElement(array $items, int $index): bool
    {
        return isset($items[$index]);
    }

    /**
     * @param list<string> $names
     */
    #[Ensures('$result !== ""')]
    public static function defaultsToTheEmptyString(array $names): string
    {
        return $names[0] ?? '';
    }

    /**
     * @param list<ReplayStatus> $statuses
     * @return list<ReplayStatus>
     */
    #[Ensures('count($result) === 0 || $result[0] === ReplayStatus::Active')]
    public static function returnsTheStatusesItWasGiven(array $statuses): array
    {
        return $statuses;
    }

    /**
     * @return list<float>
     */
    #[Ensures('count($result) === 3')]
    public static function aLiteralOfFloatsWithTheWrongLength(): array
    {
        return [-0.0, NAN];
    }

    /**
     * @param array{items: list<int>, label: string} $order
     */
    #[Ensures('$result === $order["label"]')]
    public static function aShapeHoldingAList(array $order): string
    {
        return count($order['items']) === 0 ? 'none' : $order['label'];
    }

    /**
     * NAN is equal to nothing, itself included.
     */
    #[Ensures('$result')]
    public static function aFloatIsNotFoundInItsOwnList(float $x): bool
    {
        $items = [$x];

        return in_array($x, $items, true);
    }

    /**
     * -0.0 is found as 0.0.
     */
    #[Ensures('!$result')]
    public static function zeroIsFoundInAListOfZero(float $x): bool
    {
        $items = [0.0];

        return in_array($x, $items, true);
    }

    /**
     * @param list<?int> $items
     */
    #[Ensures('!$result')]
    public static function aNullIsFoundInAListOfNullableInts(array $items): bool
    {
        return in_array(null, $items, true);
    }

    /**
     * @param list<int> $first
     * @param list<int> $second
     */
    #[Ensures('!$result')]
    public static function twoListsCanBeIdentical(array $first, array $second): bool
    {
        return $first === $second;
    }

    /**
     * @param list<int> $items
     */
    #[Ensures('count($items) < 3')]
    public static function claimsEveryListIsShort(array $items): int
    {
        return count($items);
    }

    /**
     * Z3 picks the length of the list freely: its first model for this check is a list of thousands
     * of elements, so what is printed, and replayed here, is the model of the bounded re-run.
     *
     * @param list<int> $items
     */
    #[Ensures('!$result')]
    public static function aNeedleIsFoundInALongList(array $items, int $n): bool
    {
        return in_array($n, $items, true);
    }

    private static function twice(int $x): int
    {
        return $x * 2;
    }

    #[Requires('$x >= 0')]
    private static function halve(int $x): int
    {
        return intdiv($x, 2);
    }
}
