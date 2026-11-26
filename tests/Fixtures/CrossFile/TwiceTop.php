<?php

declare(strict_types=1);

namespace PhpstanContracts\Tests\Fixtures\CrossFile;

// Declared once: its subclass TwiceMiddle is declared twice.
readonly class TwiceTop
{
    public function __construct(public int $amount)
    {
    }
}
