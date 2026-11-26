<?php

declare(strict_types=1);

namespace PhpstanContracts\Tests\Fixtures\CrossFile;

// Declared once, inside an `if`: whenever the class exists, this is the declaration that runs.
if (PHP_VERSION_ID >= 80300) {
    final readonly class Once
    {
        public function __construct(public int $amount)
        {
        }

        public static function value(): int
        {
            return 2;
        }
    }
}
