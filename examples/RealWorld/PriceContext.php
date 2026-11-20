<?php

declare(strict_types=1);

namespace PhpstanContracts\Examples\RealWorld;

use PhpstanContracts\Attribute\Ensures;

/**
 * A DTO of a data framework: promoted public readonly properties, and the mutable
 * state of the framework's base class, which the rule never reads. Only the public
 * readonly properties are modelled.
 */
final class PriceContext extends DataObject
{
    public function __construct(
        /** @var int<0, 10000> */
        public readonly int $quantity,
        public readonly ?int $customerId,
    ) {
    }

    /**
     * 5% from ten items, and 2% more for a known customer.
     */
    #[Ensures('$result >= 0 && $result <= 7')]
    #[Ensures('implies($this->quantity >= 10, $result >= 5)')]
    #[Ensures('implies($this->quantity < 10 && $this->customerId === null, $result === 0)')]
    public function discountPercent(): int
    {
        $percent = $this->quantity >= 10 ? 5 : 0;

        return $this->customerId !== null ? $percent + 2 : $percent;
    }

    /**
     * The bulk tier starts after ten items instead of at ten.
     */
    #[Ensures('implies($this->quantity >= 10, $result >= 5)')]
    public function discountPercentBroken(): int
    {
        $percent = $this->quantity > 10 ? 5 : 0;

        return $this->customerId !== null ? $percent + 2 : $percent;
    }
}
