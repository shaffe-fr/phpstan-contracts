<?php

declare(strict_types=1);

namespace PhpstanContracts\Examples\RealWorld;

use PhpstanContracts\Attribute\Ensures;
use PhpstanContracts\Attribute\Pure;

final class Nullables
{
    /**
     * Days left before an optional deadline: null when there is none.
     *
     * @param int<0, 100000>|null $deadlineDay
     * @param int<0, 100000> $today
     */
    #[Ensures('implies($deadlineDay === null, $result === null)')]
    #[Ensures('implies($deadlineDay !== null, $result !== null && $result >= 0)')]
    #[Ensures('implies($deadlineDay !== null && $deadlineDay >= $today, $result === $deadlineDay - $today)')]
    public static function daysLeft(?int $deadlineDay, int $today): ?int
    {
        if ($deadlineDay === null) {
            return null;
        }

        return max(0, $deadlineDay - $today);
    }

    /**
     * The best of two optional discounts, none when neither is set.
     *
     * @param int<0, 100>|null $customerPercent
     * @param int<0, 100>|null $campaignPercent
     */
    #[Ensures('$result >= 0 && $result <= 100')]
    #[Ensures('$result >= ($customerPercent ?? 0)')]
    #[Ensures('$result >= ($campaignPercent ?? 0)')]
    #[Ensures('implies($customerPercent === null && $campaignPercent === null, $result === 0)')]
    public static function bestDiscount(?int $customerPercent, ?int $campaignPercent): int
    {
        return max($customerPercent ?? 0, $campaignPercent ?? 0);
    }

    /**
     * Treats "no limit" as the largest allowed value.
     *
     * @param int<1, 1000>|null $limit
     */
    #[Ensures('$result >= 1 && $result <= 100')]
    public static function pageSize(?int $limit): int
    {
        if ($limit === null) {
            return 20;
        }

        return min($limit, 100);
    }

    /**
     * Same, but the clamp was forgotten on the explicit value.
     *
     * @param int<1, 1000>|null $limit
     */
    #[Ensures('$result >= 1 && $result <= 100')]
    public static function pageSizeBroken(?int $limit): int
    {
        return $limit ?? 20;
    }

    /**
     * A nullable operand needs a null check first.
     *
     * @param int<0, 100>|null $percent
     */
    #[Pure]
    public static function unchecked(?int $percent): int
    {
        return $percent + 1;
    }
}
