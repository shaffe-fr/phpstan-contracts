<?php

declare(strict_types=1);

namespace PhpstanContracts\Examples\RealWorld;

final readonly class Coupon
{
    public function __construct(
        public string $code,
        /** @var int<1, 100> */
        public int $percent,
        /** @var int<0, 100000000>|null */
        public ?int $minimumCents,
    ) {
    }
}
