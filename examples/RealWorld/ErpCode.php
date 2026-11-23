<?php

declare(strict_types=1);

namespace PhpstanContracts\Examples\RealWorld;

use PhpstanContracts\Attribute\Ensures;
use PhpstanContracts\Attribute\Requires;
use PhpstanContracts\Attribute\Throws;

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

    /**
     * The same rule as a guard that throws: the method defends itself, and #[Throws]
     * states when it does.
     */
    #[Throws(\InvalidArgumentException::class, when: '$webId < 0 || $webId >= self::RANGE_END - self::OFFSET')]
    #[Ensures('$result >= self::OFFSET && $result < self::RANGE_END')]
    public static function from(int $webId): int
    {
        if ($webId < 0 || $webId >= self::RANGE_END - self::OFFSET) {
            throw new \InvalidArgumentException('The web id is outside the range reserved for the web shop.');
        }

        return self::OFFSET + $webId;
    }

    /**
     * Off by one in the guard: the last id is let through.
     */
    #[Throws(\InvalidArgumentException::class, when: '$webId < 0 || $webId >= self::RANGE_END - self::OFFSET')]
    public static function fromBroken(int $webId): int
    {
        if ($webId < 0 || $webId > self::RANGE_END - self::OFFSET) {
            throw new \InvalidArgumentException('The web id is outside the range reserved for the web shop.');
        }

        return self::OFFSET + $webId;
    }

    /**
     * Which of the two ERP shards holds the code. The default arm is a consistency check:
     * undeclared by #[Throws], so the proof shows that it cannot be reached.
     */
    #[Requires('$webId >= 0 && $webId < 1000')]
    #[Ensures('$result >= 1 && $result <= 2')]
    #[Ensures('implies($webId < 500, $result === 1)')]
    public static function shard(int $webId): int
    {
        return match (intdiv($webId, 500)) {
            0 => 1,
            1 => 2,
            default => throw new \LogicException('Unknown shard.'),
        };
    }

    /**
     * The domain grew to 1499 but the match did not: the default arm can be reached.
     */
    #[Requires('$webId >= 0 && $webId < 1500')]
    #[Ensures('$result >= 1 && $result <= 2')]
    public static function shardBroken(int $webId): int
    {
        return match (intdiv($webId, 500)) {
            0 => 1,
            1 => 2,
            default => throw new \LogicException('Unknown shard.'),
        };
    }
}
