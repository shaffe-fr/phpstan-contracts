<?php

declare(strict_types=1);

namespace PhpstanContracts\Tests\Fixtures\CrossFile;

use PhpstanContracts\Attribute\Throws;

/**
 * A guard called from the methods of other files, which throws for an id out of range.
 */
final class WebId
{
    #[Throws(\InvalidArgumentException::class, when: '$id < 0 || $id >= 1000')]
    public static function from(int $id): int
    {
        if ($id < 0 || $id >= 1000) {
            throw new \InvalidArgumentException('The web id is out of range.');
        }

        return $id;
    }

    /** Throws only through the guard, which it calls in tail position. */
    public static function checked(int $id): int
    {
        return self::from($id);
    }
}
