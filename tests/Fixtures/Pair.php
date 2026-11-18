<?php

declare(strict_types=1);

namespace PhpstanContracts\Tests\Fixtures;

final readonly class Pair
{
    public function __construct(
        /** @var int<0, 100> */
        public int $left,
        /** @var int<0, 100> */
        public int $right,
    ) {
    }
}
