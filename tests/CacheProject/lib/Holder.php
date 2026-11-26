<?php

declare(strict_types=1);

/**
 * @template T of int
 */
abstract readonly class Holder
{
    /**
     * @param T $value
     */
    final public function __construct(public int $value)
    {
    }
}
