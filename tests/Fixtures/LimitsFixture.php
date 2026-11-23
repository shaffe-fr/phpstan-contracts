<?php

declare(strict_types=1);

namespace PhpstanContracts\Tests\Fixtures;

use PhpstanContracts\Attribute\Ensures;
use PhpstanContracts\Attribute\Requires;

final class LimitsFixture
{
    /** @param int<0, 100> $x */
    #[Ensures('$result >= 0')]
    public static function easyQuestion(int $x): int
    {
        return $x;
    }

    /**
     * @param int<1, 1000000> $page
     * @param int<1, 1000> $perPage
     */
    #[Ensures('$result % $perPage === 0')]
    public static function nonLinearDivisibility(int $page, int $perPage): int
    {
        return ($page - 1) * $perPage;
    }

    /**
     * Fermat's last theorem for the exponent three: no input satisfies the preconditions,
     * which Z3 cannot establish, so the proof of the postcondition may be vacuous.
     */
    #[Requires('$x > 0 && $y > 0 && $z > 0')]
    #[Requires('$x * $x * $x + $y * $y * $y === $z * $z * $z')]
    #[Requires('$x < 100000 && $y < 100000 && $z < 100000')]
    #[Ensures('$result === 1')]
    public static function preconditionsThatMayBeContradictory(int $x, int $y, int $z): int
    {
        return 1;
    }
}
