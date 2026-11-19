<?php

declare(strict_types=1);

namespace PhpstanContracts\Examples\RealWorld;

use PhpstanContracts\Attribute\Ensures;

/**
 * A readonly data class with its own rules: `$this` is an immutable record of its properties.
 */
final readonly class StockLine
{
    public function __construct(
        /** @var int<0, 1000000> */
        public int $onHand,
        /** @var int<0, 1000000> */
        public int $reserved,
    ) {
    }

    /**
     * What can still be sold: what is on hand minus what is reserved, never negative.
     */
    #[Ensures('$result >= 0 && $result <= $this->onHand')]
    #[Ensures('implies($this->reserved <= $this->onHand, $result === $this->onHand - $this->reserved)')]
    public function available(): int
    {
        return max(0, $this->onHand - $this->reserved);
    }

    /**
     * Over-reservation was not expected, so the difference goes negative.
     */
    #[Ensures('$result >= 0')]
    public function availableBroken(): int
    {
        return $this->onHand - $this->reserved;
    }
}
