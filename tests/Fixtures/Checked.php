<?php

declare(strict_types=1);

namespace PhpstanContracts\Tests\Fixtures;

final readonly class Checked
{
    public function __construct(public int $value)
    {
        if ($value < 0) {
            throw new \InvalidArgumentException('negative');
        }
    }
}
