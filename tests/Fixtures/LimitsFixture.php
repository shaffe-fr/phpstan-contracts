<?php

declare(strict_types=1);

namespace PhpstanContracts\Tests\Fixtures;

use PhpstanContracts\Attribute\Ensures;

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
}
