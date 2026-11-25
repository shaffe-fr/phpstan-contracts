<?php

declare(strict_types=1);

namespace PhpstanContracts\Tests\Fixtures;

use PhpstanContracts\Attribute\Ensures;

final class RunBudgetFixture
{
    /** @param int<0, 100> $x */
    #[Ensures('$result >= 0')]
    public static function first(int $x): int
    {
        return $x;
    }

    /** @param int<0, 100> $x */
    #[Ensures('$result >= 0')]
    public static function second(int $x): int
    {
        return $x;
    }

    /** @param int<0, 100> $x */
    #[Ensures('$result >= 0')]
    public static function third(int $x): int
    {
        return $x + 1;
    }
}
