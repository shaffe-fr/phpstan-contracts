<?php

declare(strict_types=1);

namespace PhpstanContracts\Examples\RealWorld;

/**
 * Shaped like the base class of a data framework (Spatie\LaravelData\Data is one): protected
 * state that the framework fills in after construction, so it is mutable.
 */
abstract class DataObject
{
    /** @var array<string, mixed> */
    protected array $state = [];

    /**
     * @param array<string, mixed> $values
     */
    public function with(array $values): static
    {
        $this->state = array_merge($this->state, $values);

        return $this;
    }
}
