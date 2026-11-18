<?php

declare(strict_types=1);

namespace PhpstanContracts\Tests\Fixtures;

final readonly class Segment
{
    public function __construct(
        public Point $start,
        public ?Point $end,
        public string $label,
    ) {
    }
}
