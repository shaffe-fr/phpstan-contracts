<?php

declare(strict_types=1);

namespace PhpstanContracts\Tests\Fixtures;

use PhpstanContracts\Attribute\Throws;

class FactoryOpenException extends \RuntimeException
{
    #[Throws(FactoryOpenException::class)]
    public static function guardLateBound(int $id): int
    {
        throw static::forId($id);
    }

    #[Throws(FactoryOpenException::class)]
    public static function guard(int $id): int
    {
        throw self::forId($id);
    }

    public static function forId(int $id): static
    {
        return new static('Invalid id ' . $id);
    }
}
