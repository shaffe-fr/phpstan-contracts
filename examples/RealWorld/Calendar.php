<?php

declare(strict_types=1);

namespace PhpstanContracts\Examples\RealWorld;

use PhpstanContracts\Attribute\Ensures;

final class Calendar
{
    /**
     * Gregorian leap year rule, checked against its four defining cases.
     *
     * @param int<1, 9999> $year
     */
    #[Ensures('implies($year % 4 !== 0, !$result)')]
    #[Ensures('implies($year % 4 === 0 && $year % 100 !== 0, $result)')]
    #[Ensures('implies($year % 100 === 0 && $year % 400 !== 0, !$result)')]
    #[Ensures('implies($year % 400 === 0, $result)')]
    public static function isLeapYear(int $year): bool
    {
        return ($year % 4 === 0 && $year % 100 !== 0) || $year % 400 === 0;
    }

    /**
     * The "every fourth year" shortcut.
     *
     * @param int<1, 9999> $year
     */
    #[Ensures('implies($year % 100 === 0 && $year % 400 !== 0, !$result)')]
    public static function isLeapYearBroken(int $year): bool
    {
        return $year % 4 === 0;
    }

    /**
     * @param int<1, 9999> $year
     * @param int<1, 12> $month
     */
    #[Ensures('$result >= 28 && $result <= 31')]
    #[Ensures('implies($month !== 2, $result >= 30)')]
    #[Ensures('implies($month === 2 && $year % 4 !== 0, $result === 28)')]
    #[Ensures('implies($month === 2 && $year % 100 === 0 && $year % 400 !== 0, $result === 28)')]
    #[Ensures('implies($month === 2 && $year % 400 === 0, $result === 29)')]
    public static function daysInMonth(int $year, int $month): int
    {
        if ($month === 2) {
            $leap = ($year % 4 === 0 && $year % 100 !== 0) || $year % 400 === 0;

            return $leap ? 29 : 28;
        }

        return match ($month) {
            4, 6, 9, 11 => 30,
            default => 31,
        };
    }
}
