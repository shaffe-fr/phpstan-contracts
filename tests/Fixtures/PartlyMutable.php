<?php

declare(strict_types=1);

namespace PhpstanContracts\Tests\Fixtures;

/**
 * One property the prover can model, one that is mutable and one of a type it does not support.
 */
final class PartlyMutable
{
    public int $count = 0;

    /**
     * @param array<string, string> $tags
     */
    public function __construct(
        public readonly int $limit,
        public readonly array $tags,
    ) {
    }
}
