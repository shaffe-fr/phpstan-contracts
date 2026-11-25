<?php

declare(strict_types=1);

namespace PhpstanContracts\Tests\Fixtures;

use PhpstanContracts\Attribute\Throws;

final class FactorySpecificException extends FactoryBaseException
{
    #[Throws(FactorySpecificException::class)]
    public static function aParentFactory(int $n): int
    {
        throw parent::make();
    }

    public static function specific(): self
    {
        return new self('Specific.');
    }
}
