<?php

declare(strict_types=1);

namespace PhpstanContracts\Tests\Fixtures\CrossFile;

// Declared twice, under conditions: PHP runs the second declaration, which overrides the
// inherited method, while PHPStan reflects the first, which inherits it.
if (PHP_VERSION_ID < 80300) {
    final class TwiceKid extends TwiceBase
    {
    }
} else {
    final class TwiceKid extends TwiceBase
    {
        public static function value(): int
        {
            return 2;
        }
    }
}
