<?php

declare(strict_types=1);

namespace CacheProject;

final readonly class Box
{
    /**
     * @param int<0, Amounts::MAX> $amount
     */
    public function __construct(public int $amount)
    {
    }
}
