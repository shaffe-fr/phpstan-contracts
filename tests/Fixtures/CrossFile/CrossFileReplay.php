<?php

declare(strict_types=1);

namespace PhpstanContracts\Tests\Fixtures\CrossFile;

use PhpstanContracts\Attribute\Ensures;
use PhpstanContracts\Attribute\Pure;
use PhpstanContracts\Attribute\Requires;

/**
 * Every method is refuted through a call to, or an object of, another file, and takes
 * scalars only, so that CounterexampleReplayTest can run it on the reported inputs.
 */
final class CrossFileReplay
{
    #[Requires('$percent >= 0 && $percent <= 100 && $cents >= 0 && $cents <= 1000000')]
    #[Ensures('$result === $cents')]
    public static function surchargeIsIgnored(int $percent, int $cents): int
    {
        return (new Tariff(0, $percent))->withSurcharge($cents);
    }

    // The name $cents is the one of the callee's parameter, so that the replay can evaluate its precondition.
    #[Requires('$percent >= 0 && $percent <= 100')]
    #[Pure]
    public static function surchargeOfAnyAmount(int $percent, int $cents): int
    {
        return (new Tariff(0, $percent))->withSurcharge($cents);
    }

    #[Requires('$baseCents >= 0 && $baseCents <= 10000')]
    #[Pure]
    public static function scaledPastTheIntRange(int $baseCents, int $factor): int
    {
        return (new Tariff($baseCents, 0))->scaled($factor);
    }

    #[Ensures('$result <= 1000000')]
    public static function doubledClampOverThreeFiles(int $cents): int
    {
        return Steps::doubledClamp($cents);
    }

    #[Requires('$percent >= 0 && $percent <= 100 && $cents >= 0 && $cents <= 1000000')]
    #[Ensures('$result === $cents')]
    public static function nonStrictSurchargeIsIgnored(int $percent, int $cents): int
    {
        return (new TariffNonStrict(0, $percent))->withSurcharge($cents);
    }
}
