<?php

declare(strict_types=1);

namespace PhpstanContracts\Tests\Fixtures\CrossFile;

// Each class is declared twice, under conditions: PHP runs the second declaration,
// while PHPStan reflects the first.
if (PHP_VERSION_ID < 80300) {
    final class Twice
    {
        public static function value(): int
        {
            return 1;
        }
    }

    final readonly class TwiceData
    {
        public function __construct(public int $amount)
        {
        }
    }
} else {
    final class Twice
    {
        public static function value(): int
        {
            return 2;
        }
    }

    final readonly class TwiceData
    {
        public function __construct(public int $amount)
        {
            if ($amount < 0) {
                throw new \InvalidArgumentException('The amount cannot be negative.');
            }
        }
    }
}
