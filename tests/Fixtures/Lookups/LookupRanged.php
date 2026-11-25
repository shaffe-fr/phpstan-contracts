<?php

declare(strict_types=1);

namespace PhpstanContracts\Tests\Fixtures\Lookups;

use PhpstanContracts\Tests\Fixtures\Lookups\LookupLimits as Limits;

use const PhpstanContracts\Tests\Fixtures\SEVEN;

/**
 * Each PHPDoc names a constant of another file, which PHPStan folds into the type.
 *
 * @template T of LookupRate
 * @phpstan-import-type Share from LookupShares
 */
final class LookupRanged
{
    /**
     * @param int<0, Limits::MAX> $x
     */
    public static function capped(int $x): int
    {
        return $x;
    }

    /**
     * @return LookupFlags::FLAG_*
     */
    public static function flag(): int
    {
        return LookupFlags::FLAG_A;
    }

    /**
     * @return SEVEN
     */
    public static function seven(): int
    {
        return 7;
    }

    /**
     * @param T::CODE $code
     */
    public static function code(string $code): string
    {
        return $code;
    }
}
