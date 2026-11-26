<?php

declare(strict_types=1);

namespace PhpstanContracts\Examples\RealWorld;

/**
 * What a customer paid and what is given back. A plain record with an empty constructor:
 * RefundPolicy.php builds it from another file.
 */
final readonly class Refund
{
    public function __construct(
        public int $paidCents,
        public int $refundedCents,
    ) {
    }
}
