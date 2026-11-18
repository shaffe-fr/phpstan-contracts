<?php

declare(strict_types=1);

namespace PhpstanContracts\Examples\RealWorld;

use PhpstanContracts\Attribute\Ensures;
use PhpstanContracts\Attribute\Requires;

/**
 * True statements that Z3 cannot decide, kept apart because each one waits for the full timeout.
 */
final class SolverLimits
{
    /**
     * Divisibility by a variable modulus mixes a product with `mod` by a variable:
     * Z3 answers "unknown". Pagination::offset() proves the same fact as an identity.
     *
     * @param int<1, 1000000> $page
     * @param int<1, 1000> $perPage
     */
    #[Ensures('$result % $perPage === 0')]
    public static function offsetDivisibility(int $page, int $perPage): int
    {
        return ($page - 1) * $perPage;
    }

    /**
     * Multiplying two float variables is the expensive case of the floating-point theory:
     * the solver has to reason about a 53-bit by 53-bit multiplier. A constant factor is cheap.
     */
    #[Requires('is_finite($price) && $price >= 0.0 && $price <= 1000000.0')]
    #[Requires('$ratio >= 0.0 && $ratio <= 1.0')]
    #[Ensures('$result <= $price')]
    public static function scaled(float $price, float $ratio): float
    {
        return $price * $ratio;
    }
}
