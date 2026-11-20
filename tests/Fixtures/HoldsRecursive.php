<?php

declare(strict_types=1);

namespace PhpstanContracts\Tests\Fixtures;

/**
 * A field of a recursive class rejects the whole class, not only that field.
 */
final readonly class HoldsRecursive
{
    public function __construct(public int $count, public Recursive $chain)
    {
    }
}
