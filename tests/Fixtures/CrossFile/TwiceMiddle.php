<?php

declare(strict_types=1);

namespace PhpstanContracts\Tests\Fixtures\CrossFile;

// Declared twice, under conditions: PHP runs the second declaration, whose constructor
// throws, while PHPStan reflects the first, which inherits the constructor of TwiceTop.
if (PHP_VERSION_ID < 80300) {
    readonly class TwiceMiddle extends TwiceTop
    {
    }
} else {
    readonly class TwiceMiddle extends TwiceTop
    {
        public function __construct(int $amount)
        {
            if ($amount < 0) {
                throw new \InvalidArgumentException('The amount cannot be negative.');
            }
            parent::__construct($amount);
        }
    }
}
