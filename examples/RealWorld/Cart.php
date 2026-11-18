<?php

declare(strict_types=1);

namespace PhpstanContracts\Examples\RealWorld;

final readonly class Cart
{
    public function __construct(
        /** @var int<0, 100000000> */
        public int $subtotalCents,
        public ?Coupon $coupon,
        public Currency $currency,
    ) {
    }
}
