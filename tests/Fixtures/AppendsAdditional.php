<?php

declare(strict_types=1);

namespace PhpstanContracts\Tests\Fixtures;

/**
 * The mutable state that a data framework base class brings in through its traits, as
 * Spatie\LaravelData\Data does with `$_additional`.
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
