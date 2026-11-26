<?php

declare(strict_types=1);

namespace PhpstanContracts\Tests\Fixtures\CrossFile;

// Declared twice, under conditions: PHP runs the second declaration, which overrides the
// inherited method, while PHPStan reflects the first, which inherits it.
if (PHP_VERSION_ID < 80300) {
    final readonly class TwiceDataKid extends TwiceDataBase
    {
    }
} else {
    final readonly class TwiceDataKid extends TwiceDataBase
    {
        public function amount(): int
        {
            return 2;
        }
    }
}
