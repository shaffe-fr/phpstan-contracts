<?php

declare(strict_types=1);

namespace PhpstanContracts\Solver;

use PhpstanContracts\Attribute\Ensures;

/**
 * Arithmetic of the time limits of a Z3 run. It is verified by this package itself,
 * every time the package is analysed.
 */
final class TimeLimits
{
    /**
     * Seconds after which the whole Z3 process is killed. Z3 stops each check on its
     * own after `$perCheckMs`, and answers `unknown`. The process limit must therefore
     * never come before all of those limits have elapsed, or a slow but honest answer
     * would be reported as a solver failure.
     *
     * @param int<1, 86400000> $perCheckMs at most a day
     * @param int<1, 100000> $checkCount
     */
    #[Ensures('$result >= 10')]
    #[Ensures('$result * 1000 >= $perCheckMs * $checkCount + 10000')]
    #[Ensures('$result * 1000 < $perCheckMs * $checkCount + 11000')]
    public static function processSeconds(int $perCheckMs, int $checkCount): int
    {
        return intdiv($perCheckMs * $checkCount + 999, 1000) + 10;
    }

    /**
     * Milliseconds after which the Z3 process of a run under a budget is killed, `$remainingMs` being
     * what is left of the budget. It is the limit of {@see self::processSeconds()}, which never comes
     * before the limits of the checks, unless the budget is spent first: then it is the budget that
     * cuts the run, and the process is never kept beyond it.
     *
     * @param int<1, 86400000> $perCheckMs at most a day
     * @param int<1, 100000> $checkCount
     * @param int<1, 86400000> $remainingMs at most a day
     */
    #[Ensures('$result >= 1')]
    #[Ensures('$result <= $remainingMs')]
    #[Ensures('$result === $remainingMs || $result >= $perCheckMs * $checkCount + 10000')]
    public static function killMs(int $perCheckMs, int $checkCount, int $remainingMs): int
    {
        $full = self::processSeconds($perCheckMs, $checkCount) * 1000;
        if ($remainingMs < $full) {
            return $remainingMs;
        }

        return $full;
    }
}
