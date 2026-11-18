<?php

declare(strict_types=1);

namespace PhpstanContracts\Tests\Fixtures;

final class Tagged
{
    public function __construct(
        public readonly int $id,
        public readonly string $tag,
    ) {
    }
}
