<?php

declare(strict_types=1);

namespace PhpstanContracts\Tests\Fixtures\CrossFile;

/**
 * The middle of a chain of calls over three files: CrossFileFixture -> Steps -> Tariff.
 */
final class Steps
{
    public static function doubledClamp(int $cents): int
    {
        return Tariff::clamp($cents) * 2;
    }
}
