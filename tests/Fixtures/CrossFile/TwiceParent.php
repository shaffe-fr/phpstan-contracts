<?php

declare(strict_types=1);

namespace PhpstanContracts\Tests\Fixtures\CrossFile;

// Declared twice, under conditions: PHP runs the second declaration, while PHPStan reflects the first.
if (PHP_VERSION_ID < 80300) {
    abstract class TwiceParent
    {
        final protected function value(): int
        {
            return 1;
        }
    }
} else {
    abstract class TwiceParent
    {
        final protected function value(): int
        {
            return 2;
        }
    }
}
