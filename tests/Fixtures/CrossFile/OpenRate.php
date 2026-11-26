<?php

declare(strict_types=1);

namespace PhpstanContracts\Tests\Fixtures\CrossFile;

/**
 * Not final: a subclass can replace percent().
 */
readonly class OpenRate
{
    /**
     * Final, so that every OpenRate, subclasses included, has $percent set.
     *
     * @param int<0, 100> $percent
     */
    final public function __construct(public int $percent)
    {
    }

    public function percent(): int
    {
        return $this->percent;
    }
}
