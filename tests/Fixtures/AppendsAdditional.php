<?php

declare(strict_types=1);

namespace PhpstanContracts\Tests\Fixtures;

/**
 * The mutable state that Spatie\LaravelData\Data brings in through its traits.
 */
trait AppendsAdditional
{
    /** @var array<string, mixed> */
    protected array $_additional = [];

    /**
     * @param array<string, mixed> $additional
     */
    public function additional(array $additional): static
    {
        $this->_additional = $additional;

        return $this;
    }
}
