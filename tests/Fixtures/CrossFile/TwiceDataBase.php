<?php

declare(strict_types=1);

namespace PhpstanContracts\Tests\Fixtures\CrossFile;

// Declared once: its subclass TwiceDataKid is declared twice.
readonly class TwiceDataBase
{
    public function __construct(public int $amount)
    {
    }

    public function amount(): int
    {
        return 1;
    }
}
