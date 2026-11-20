<?php

declare(strict_types=1);

namespace PhpstanContracts\Tests\Fixtures;

use PhpstanContracts\Attribute\Ensures;
use PhpstanContracts\Attribute\Pure;

/**
 * A DTO of a data framework: promoted public readonly properties, and inherited
 * state that the proof never reads.
 */
final class PriceContext extends FrameworkData
{
    public function __construct(
        /** @var int<0, 1000> */
        public readonly int $quantity,
        public readonly ?int $customerId,
    ) {
    }

    #[Ensures('$result === ($this->quantity >= 10)')]
    public function isBulk(): bool
    {
        return $this->quantity >= 10;
    }

    #[Pure]
    public function readsInheritedState(): bool
    {
        return $this->_additional === [];
    }
}
