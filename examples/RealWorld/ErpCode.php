<?php

declare(strict_types=1);

namespace PhpstanContracts\Examples\RealWorld;

use PhpstanContracts\Attribute\Ensures;

/**
 * The ERP reserves a range of customer codes for the web shop. A code outside that
 * range would overwrite a record that the ERP owns, so the range is the rule to prove.
 */
final class ErpCode
{
    public const int OFFSET = 98_000_000;

    public const int RANGE_END = 99_000_000;

    /**
     * The ERP code of a web customer, null when the id does not fit in the range.
     */
    #[Ensures('implies($result !== null, $result >= 98000000 && $result < 99000000)')]
    #[Ensures('implies($result !== null, $result >= self::OFFSET && $result < ErpCode::RANGE_END)')]
    public static function tryFrom(int $webId): ?int
    {
        return $webId >= 0 && $webId < self::RANGE_END - self::OFFSET
            ? self::OFFSET + $webId
            : null;
    }

    /**
     * Off by one: the last id gets the first code after the range.
     */
    #[Ensures('implies($result !== null, $result < self::RANGE_END)')]
    public static function tryFromBroken(int $webId): ?int
    {
        return $webId >= 0 && $webId <= self::RANGE_END - self::OFFSET
            ? self::OFFSET + $webId
            : null;
    }
}
