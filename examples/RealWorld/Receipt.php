<?php

declare(strict_types=1);

namespace PhpstanContracts\Examples\RealWorld;

final readonly class Receipt
{
    public function __construct(
        /** @var int<0, 100000000> */
        public int $discountCents,
        /** @var int<0, 100000000> */
        public int $totalCents,
        public bool $couponApplied,
    ) {
    }
}
