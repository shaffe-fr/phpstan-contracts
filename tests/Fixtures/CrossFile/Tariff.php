<?php

declare(strict_types=1);

namespace PhpstanContracts\Tests\Fixtures\CrossFile;

use PhpstanContracts\Attribute\Requires;

/**
 * A data class whose methods are called, and which is built, from other files.
 */
final readonly class Tariff
{
    public function __construct(
        /** @var int<0, 10000> */
        public int $baseCents,
        /** @var int<0, 100> */
        public int $percent,
    ) {
    }

    #[Requires('$cents >= 0 && $cents <= 1000000')]
    final public function withSurcharge(int $cents): int
    {
        return $cents + intdiv($cents * $this->percent, 100);
    }

    public function scaled(int $factor): int
    {
        return $this->baseCents * $factor;
    }

    public static function clamp(int $cents): int
    {
        if ($cents < 0) {
            return 0;
        }
        if ($cents > 1000000) {
            return 1000000;
        }

        return $cents;
    }
}
