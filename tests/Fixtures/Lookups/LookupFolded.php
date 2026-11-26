<?php

declare(strict_types=1);

namespace PhpstanContracts\Tests\Fixtures\Lookups;

/**
 * Each PHPDoc names a constant or an enum whose initializer reads a constant of another file.
 */
final class LookupFolded
{
    public const OWN = LookupDeepSelf::VALUE;

    /**
     * @param int<0, self::OWN> $own
     * @param int<0, LookupChained::MAX> $chained
     * @param value-of<LookupBacked> $backed
     */
    public static function folded(int $own, int $chained, int $backed): int
    {
        return $own + $chained + $backed;
    }
}
