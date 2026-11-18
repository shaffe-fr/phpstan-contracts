<?php

declare(strict_types=1);

namespace PhpstanContracts\Tests\Fixtures;

final class Assigned
{
    public readonly int $value;

    public function __construct(int $value)
    {
        $this->value = $value;
    }
}
