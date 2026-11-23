<?php

declare(strict_types=1);

namespace PhpstanContracts\Tests\Fixtures;

/**
 * A readonly data class with a list property, and a shape holding a list.
 */
final class ListHolder
{
    /**
     * @param list<int<0, 100>> $values
     */
    public function __construct(
        public readonly array $values,
    ) {
    }
}
