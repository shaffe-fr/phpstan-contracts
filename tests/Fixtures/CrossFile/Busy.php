<?php

declare(strict_types=1);

namespace PhpstanContracts\Tests\Fixtures\CrossFile;

/**
 * A data class whose constructor does more than promote its property.
 */
final readonly class Busy
{
    public function __construct(public int $count)
    {
        if ($count < 0) {
            throw new \InvalidArgumentException('The count cannot be negative.');
        }
    }
}
