<?php

declare(strict_types=1);

namespace PhpstanContracts\Tests\Fixtures;

/**
 * A readonly data class with an array shape property.
 */
final class ShapeHolder
{
    /**
     * @param array{net: int, vat: int} $totals
     */
    public function __construct(
        public readonly array $totals,
    ) {
    }
}
