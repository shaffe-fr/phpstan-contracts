<?php

declare(strict_types=1);

namespace PhpstanContracts\Tests\Fixtures;

use PhpstanContracts\Attribute\Throws;

final class FactorySelfException extends \RuntimeException
{
    #[Throws(FactorySelfException::class, when: '$id < 0')]
    public static function guard(int $id): int
    {
        if ($id < 0) {
            throw self::forId($id);
        }

        return $id;
    }

    #[Throws(FactorySelfException::class, when: '$id < 0')]
    public static function guardLateBound(int $id): int
    {
        if ($id < 0) {
            throw static::forId($id);
        }

        return $id;
    }

    #[Throws(FactorySelfException::class, when: '$id < 0')]
    public static function guardWithAPrivateFactory(int $id): int
    {
        if ($id < 0) {
            throw self::hidden();
        }

        return $id;
    }

    public static function forId(int $id): self
    {
        return new self('Invalid id ' . $id);
    }

    private static function hidden(): self
    {
        return new self('Hidden.');
    }
}
