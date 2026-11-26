<?php

declare(strict_types=1);

namespace CacheProject;

final readonly class Tagged
{
    use Tags;

    public function __construct(public int $tag)
    {
    }
}
