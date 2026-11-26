<?php

// No strict_types: the callee is translated the same way, since the translation only
// accepts the conversions PHP makes in both modes.

namespace PhpstanContracts\Tests\Fixtures\CrossFile;

use PhpstanContracts\Attribute\Requires;

final readonly class TariffNonStrict
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

    // Without strict_types, PHP would pass 2.0 to clamp() as 2; with them, it would throw.
    public static function clampOf(float $amount): int
    {
        return Tariff::clamp($amount);
    }
}
