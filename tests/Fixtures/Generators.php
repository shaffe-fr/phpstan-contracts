<?php

declare(strict_types=1);

namespace PhpstanContracts\Tests\Fixtures;

use PhpstanContracts\Attribute\Ensures;

/**
 * A `yield` anywhere in a body makes the method return a Generator, even where no path reaches it.
 */
final class Generators
{
    /**
     * @return int
     */
    #[Ensures('$result === 5')]
    public static function yieldAfterTheReturn()
    {
        return 5;
        yield 1;
    }

    /**
     * @return int
     */
    #[Ensures('$result === 5')]
    public static function yieldInADeadBranch()
    {
        if (false) {
            yield 1;
        }

        return 5;
    }

    #[Ensures('$result === 5')]
    public static function callsAGenerator(): int
    {
        return self::five();
    }

    #[Ensures('$result === 5')]
    public static function yieldInAClosureBelongsToTheClosure(): int
    {
        return 5;
        $generator = static function () {
            yield 1;
        };
    }

    /**
     * @return int
     */
    private static function five()
    {
        return 5;
        yield 1;
    }
}
