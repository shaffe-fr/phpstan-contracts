<?php

declare(strict_types=1);

namespace PhpstanContracts\Examples\RealWorld;

use PhpstanContracts\Attribute\Ensures;

/**
 * The amounts of the lines of an order, as a list read by index.
 */
final class OrderLines
{
    /**
     * Amount of the line at $index, or 0 when there is no such line.
     *
     * @param list<int<0, 10000000>> $amounts
     */
    #[Ensures('$result >= 0')]
    public static function lineAmount(array $amounts, int $index): int
    {
        if ($index >= 0 && $index < count($amounts)) {
            return $amounts[$index];
        }

        return 0;
    }

    /**
     * The off-by-one of the guard: when $index equals count($amounts), the key
     * does not exist, PHP emits a warning and reads null.
     *
     * @param list<int<0, 10000000>> $amounts
     */
    #[Ensures('$result >= 0')]
    public static function lineAmountBroken(array $amounts, int $index): int
    {
        if ($index >= 0 && $index <= count($amounts)) {
            return $amounts[$index];
        }

        return 0;
    }

    /**
     * A non-empty list always has a first element, so no guard is needed.
     *
     * @param non-empty-list<int<0, 10000000>> $amounts
     */
    #[Ensures('$result >= 0')]
    public static function firstAmount(array $amounts): int
    {
        return $amounts[0];
    }

    /**
     * Adds a line when its amount is valid, and keeps the order unchanged otherwise.
     *
     * @param list<int<0, 10000000>> $amounts
     * @return list<int<0, 10000000>>
     */
    #[Ensures('count($result) <= count($amounts) + 1')]
    #[Ensures('count($result) >= count($amounts)')]
    public static function withLine(array $amounts, int $amount): array
    {
        if ($amount >= 0 && $amount <= 10000000) {
            return [...$amounts, $amount];
        }

        return $amounts;
    }

    /**
     * Appends without checking the amount: a negative or too large one ends up in
     * the list, which the declared element range does not allow.
     *
     * @param list<int<0, 10000000>> $amounts
     * @return list<int<0, 10000000>>
     */
    #[Ensures('count($result) === count($amounts) + 1')]
    public static function withLineBroken(array $amounts, int $amount): array
    {
        return [...$amounts, $amount];
    }

    /**
     * @param list<string> $blockedSkus
     */
    #[Ensures('implies(count($blockedSkus) === 0, !$result)')]
    public static function isBlocked(array $blockedSkus, string $sku): bool
    {
        return in_array($sku, $blockedSkus, true);
    }
}
