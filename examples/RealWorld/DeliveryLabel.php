<?php

declare(strict_types=1);

namespace PhpstanContracts\Examples\RealWorld;

use PhpstanContracts\Attribute\Ensures;
use PhpstanContracts\Attribute\Requires;

/**
 * Labels built from an int: `.`, interpolation and `(string)` write its decimal digits.
 */
final class DeliveryLabel
{
    /**
     * @param int<1, 60> $days
     */
    #[Ensures('implies($days === 1, $result === "Ships in 1 day")')]
    #[Ensures('implies($days > 1, $result === "Ships in " . $days . " days")')]
    public static function inStockDelay(int $days): string
    {
        return $days === 1 ? 'Ships in 1 day' : "Ships in $days days";
    }

    /**
     * The singular was forgotten.
     *
     * @param int<1, 60> $days
     */
    #[Ensures('implies($days === 1, $result === "Ships in 1 day")')]
    public static function inStockDelayBroken(int $days): string
    {
        return "Ships in $days days";
    }

    /**
     * @param int<1, 99999999> $id
     */
    #[Ensures('str_starts_with($result, "#")')]
    #[Ensures('strlen($result) >= 2 && strlen($result) <= 9')]
    public static function orderReference(int $id): string
    {
        return '#' . (string) $id;
    }

    /**
     * The plural, which only makes sense from two days: the call site must respect that.
     */
    #[Requires('$days >= 2')]
    #[Ensures('$result === $days . " days"')]
    public static function plural(int $days): string
    {
        return "$days days";
    }

    /**
     * Calls plural() only where its precondition holds, and the proof follows the call.
     *
     * @param int<1, 60> $days
     */
    #[Ensures('implies($days === 1, $result === "1 day")')]
    #[Ensures('implies($days > 1, $result === $days . " days")')]
    public static function shortDelay(int $days): string
    {
        return $days === 1 ? '1 day' : self::plural($days);
    }

    /**
     * The guard is `>= 1`, so plural(1) is called and breaks its precondition.
     *
     * @param int<0, 60> $days
     */
    #[Ensures('strlen($result) >= 1')]
    public static function shortDelayBroken(int $days): string
    {
        return $days >= 1 ? self::plural($days) : '0 days';
    }
}
