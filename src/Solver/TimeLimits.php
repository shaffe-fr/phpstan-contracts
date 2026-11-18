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
}
