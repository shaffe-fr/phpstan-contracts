<?php

declare(strict_types=1);

namespace PhpstanContracts\Tests\Fixtures\CrossFile;

// Declared once: its subclass TwiceKid is declared twice.
class TwiceBase
{
    public static function value(): int
    {
        return 1;
    }
}
