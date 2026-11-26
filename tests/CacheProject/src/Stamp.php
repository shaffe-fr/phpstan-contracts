<?php

declare(strict_types=1);

namespace CacheProject;

final readonly class Stamp extends StampBase
{
    public function __construct(public int $stamp)
    {
    }
}
