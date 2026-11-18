<?php

declare(strict_types=1);

namespace PhpstanContracts\Tests\Fixtures;

final readonly class Percent
{
    public function __construct(
        /** @var int<0, 100> */
        public int $value,
    ) {
    }
}
