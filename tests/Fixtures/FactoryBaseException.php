<?php

declare(strict_types=1);

namespace PhpstanContracts\Tests\Fixtures;

class FactoryBaseException extends \RuntimeException
{
    public static function make(): static
    {
        return new static('Base.');
    }
}
