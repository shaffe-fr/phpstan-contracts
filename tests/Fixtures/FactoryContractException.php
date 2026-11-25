<?php

declare(strict_types=1);

namespace PhpstanContracts\Tests\Fixtures;

use PhpstanContracts\Attribute\Requires;
use PhpstanContracts\Attribute\Throws;

final class FactoryContractException extends \RuntimeException
{
    #[Requires('$n > 0')]
    public static function guarded(int $n): self
    {
        return new self('Guarded.');
    }

    #[Throws(\LogicException::class)]
    public static function thatThrows(): self
    {
        throw new \LogicException('Thrown by the factory.');
    }
}
