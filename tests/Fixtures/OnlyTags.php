<?php

declare(strict_types=1);

namespace PhpstanContracts\Tests\Fixtures;

/**
 * Its only public readonly property has a type the prover does not support.
 */
final readonly class OnlyTags
{
    /**
     * @param array<string, string> $tags
     */
    public function __construct(public array $tags)
    {
    }
}
