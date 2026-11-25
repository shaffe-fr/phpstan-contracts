<?php

declare(strict_types=1);

namespace PhpstanContracts\Examples\RealWorld;

use PhpstanContracts\Attribute\Ensures;
use PhpstanContracts\Attribute\Requires;
use PhpstanContracts\Attribute\Throws;

/**
 * The same reserved range as ErpCode, with a guard that throws an exception built by a
 * static factory instead of `new`. The factory is named in the guard, and its return
 * type, the exception class, is what #[Throws] is matched against.
 */
final class CustomerCode
{
    public const int OFFSET = 98_000_000;
    public const int RANGE_END = 99_000_000;

    /**
     * The code of a web customer. The ids that do not fit in the range are refused with the
     * factory of the exception, which receives the id, the code that it would give and the range.
     */
    #[Requires('$webId > -1000000000 && $webId < 1000000000')]
    #[Throws(CodeRangeException::class, when: '$webId < 0 || $webId >= self::RANGE_END - self::OFFSET')]
    #[Ensures('$result >= self::OFFSET && $result < self::RANGE_END')]
    public static function from(int $webId): int
    {
        $code = self::OFFSET + $webId;
        if ($code < self::OFFSET || $code >= self::RANGE_END) {
            throw CodeRangeException::outOfReservedRange($webId, $code, self::OFFSET, self::RANGE_END);
        }

        return $code;
    }

    /**
     * Off by one in the guard: the first code after the range is let through.
     */
    #[Requires('$webId > -1000000000 && $webId < 1000000000')]
    #[Throws(CodeRangeException::class, when: '$webId < 0 || $webId >= self::RANGE_END - self::OFFSET')]
    public static function fromBroken(int $webId): int
    {
        $code = self::OFFSET + $webId;
        if ($code < self::OFFSET || $code > self::RANGE_END) {
            throw CodeRangeException::outOfReservedRange($webId, $code, self::OFFSET, self::RANGE_END);
        }

        return $code;
    }
}
