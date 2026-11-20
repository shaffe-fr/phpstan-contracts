<?php

declare(strict_types=1);

namespace PhpstanContracts\Examples\RealWorld;

use PhpstanContracts\Attribute\Ensures;

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
}
