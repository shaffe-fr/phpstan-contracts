<?php

declare(strict_types=1);

namespace PhpstanContracts\Examples\RealWorld;

/**
 * Shaped like the base class of a data framework, such as spatie/laravel-data: state
 * that the framework fills in after construction, so it is not readonly.
 */
abstract class DataObject
{
    /** @var array<string, mixed> */
    public array $additional = [];

    /**
     * @param array<string, mixed> $values
     */
    public function with(array $values): static
    {
        $this->additional = array_merge($this->additional, $values);

        return $this;
    }
}
