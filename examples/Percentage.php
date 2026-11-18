<?php

declare(strict_types=1);

namespace PhpstanContracts\Examples;

final readonly class Percentage
{
    public function __construct(public int $value)
    {
        if ($value < 0 || $value > 100) {
            throw new \InvalidArgumentException('A percentage is between 0 and 100.');
        }
    }
}
