<?php

declare(strict_types=1);

namespace PhpstanContracts\Examples\RealWorld;

use PhpstanContracts\Attribute\Ensures;
use PhpstanContracts\Attribute\Requires;

final class RateLimit
{
    /**
     * Token bucket refill: tokens regained since the last request, never above capacity.
     *
     * @param int<1, 100000> $capacity
     * @param int<0, 100000> $tokens
     * @param int<0, 86400000> $elapsedMs at most one day
     * @param int<0, 10000> $refillPerSecond
     */
    #[Requires('$tokens <= $capacity')]
    #[Ensures('$result >= $tokens')]
    #[Ensures('$result <= $capacity')]
    public static function refilledTokens(int $capacity, int $tokens, int $elapsedMs, int $refillPerSecond): int
    {
        return min($capacity, $tokens + intdiv($elapsedMs * $refillPerSecond, 1000));
    }

    /**
     * Same code, but nothing says how large $elapsedMs can get.
     *
     * @param int<1, 100000> $capacity
     * @param int<0, 100000> $tokens
     * @param int<0, 10000> $refillPerSecond
     */
    #[Requires('$tokens <= $capacity')]
    #[Ensures('$result >= $tokens')]
    public static function refilledTokensUnbounded(int $capacity, int $tokens, int $elapsedMs, int $refillPerSecond): int
    {
        return min($capacity, $tokens + intdiv($elapsedMs * $refillPerSecond, 1000));
    }

    /**
     * Whole seconds to wait until $cost tokens are available.
     *
     * @param int<1, 100000> $cost
     * @param int<0, 100000> $tokens
     * @param int<1, 10000> $refillPerSecond
     */
    #[Ensures('$result >= 0')]
    #[Ensures('implies($tokens >= $cost, $result === 0)')]
    #[Ensures('$result * $refillPerSecond >= $cost - $tokens')]
    public static function secondsUntilAvailable(int $cost, int $tokens, int $refillPerSecond): int
    {
        $missing = max(0, $cost - $tokens);

        return intdiv($missing + $refillPerSecond - 1, $refillPerSecond);
    }
}
