<?php

declare(strict_types=1);

namespace PhpstanContracts\Tests\Fixtures;

final class Hidden
{
    public function __construct(private readonly int $secret)
    {
    }

    public function secret(): int
    {
        return $this->secret;
    }
}
