<?php

declare(strict_types=1);

namespace PhpstanContracts\Tests\Fixtures;

/**
 * A readonly data class with a float field in an array shape property.
 */
final class FloatShapeHolder
{
    /**
     * @param array{rate: float} $rates
     */
    public function __construct(
        public readonly array $rates,
    ) {
    }
}
