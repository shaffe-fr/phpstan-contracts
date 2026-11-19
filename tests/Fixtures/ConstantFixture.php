<?php

declare(strict_types=1);

namespace PhpstanContracts\Tests\Fixtures;

use PhpstanContracts\Attribute\Ensures;
use PhpstanContracts\Attribute\Pure;

final class ConstantFixture extends ConstantBase
{
    public const int OFFSET = 98_000_000;

    public const int RANGE_END = 99_000_000;

    public const SIZE = self::RANGE_END - self::OFFSET;

    public const float RATE = 0.2;

    public const float WHOLE = 1;

    public const string PREFIX = 'WEB-';

    public const bool ENABLED = true;

    public const DEFAULT_LEVEL = Level::Medium;

    /** @var list<int> */
    public const array CODES = [1, 2];

    public const ?float MAYBE_WHOLE = 1;

    public const float|string MIXED_WHOLE = 2;

    private const int SECRET = 7;

    #[Ensures('$result === 1.0')]
    public static function nullableFloatConstantWrittenAsAnInt(): ?float
    {
        return self::MAYBE_WHOLE;
    }

    #[Ensures('$result')]
    public static function unionFloatConstantWrittenAsAnInt(): bool
    {
        return self::MIXED_WHOLE === 2.0;
    }

    #[Ensures('$result === 4')]
    public static function ownProtectedConstant(): int
    {
        return self::GUARDED;
    }

    #[Ensures('implies($result !== null, $result >= 98000000 && $result < 99000000)')]
    public static function tryFrom(int $webId): ?int
    {
        return $webId >= 0 && $webId < self::RANGE_END - self::OFFSET
            ? self::OFFSET + $webId
            : null;
    }

    #[Ensures('implies($result !== null, $result >= self::OFFSET && $result < ConstantFixture::RANGE_END)')]
    #[Ensures('implies($webId >= 0 && $webId < ConstantFixture::SIZE, $result === $webId + self::OFFSET)')]
    public static function constantsInContracts(int $webId): ?int
    {
        return $webId >= 0 && $webId < self::SIZE ? self::OFFSET + $webId : null;
    }

    #[Ensures('$result === 1000000')]
    public static function foldedConstantExpression(): int
    {
        return self::SIZE;
    }

    #[Ensures('$result === 0.2')]
    public static function floatConstant(): float
    {
        return self::RATE;
    }

    #[Ensures('$result === 1.0')]
    public static function floatConstantWrittenAsAnInt(): float
    {
        return self::WHOLE;
    }

    #[Ensures('str_starts_with($result, "WEB-")')]
    #[Ensures('strlen($result) === strlen($id) + 4')]
    public static function stringConstant(string $id): string
    {
        return self::PREFIX . $id;
    }

    #[Ensures('$result === $flag')]
    public static function boolConstant(bool $flag): bool
    {
        return self::ENABLED && $flag;
    }

    #[Ensures('$result === Level::Medium')]
    public static function constantHoldingAnEnumCase(): Level
    {
        return self::DEFAULT_LEVEL;
    }

    #[Ensures('$result === 98000000')]
    public static function staticOnAFinalClass(): int
    {
        return static::OFFSET;
    }

    #[Ensures('$result === 500')]
    public static function inheritedConstant(): int
    {
        return self::LIMIT;
    }

    #[Ensures('$result === ConstantBase::LIMIT')]
    public static function constantOfAnotherClass(): int
    {
        return ConstantBase::LIMIT;
    }

    #[Ensures('$result === 7')]
    public static function ownPrivateConstant(): int
    {
        return self::SECRET;
    }

    #[Ensures('implies($result !== null, $result >= 98000000 && $result < 99000000)')]
    public static function tryFromBroken(int $webId): ?int
    {
        return $webId >= 0 && $webId <= self::RANGE_END - self::OFFSET
            ? self::OFFSET + $webId
            : null;
    }

    #[Pure]
    public static function arrayConstant(): int
    {
        $codes = self::CODES;

        return 1;
    }

    #[Pure]
    public static function parentConstant(): int
    {
        return parent::LIMIT;
    }

    #[Pure]
    public static function classNameConstant(): int
    {
        $name = self::class;

        return 1;
    }

    #[Pure]
    public static function privateConstantOfAnotherClass(): int
    {
        return ConstantBase::HIDDEN; // @phpstan-ignore classConstant.private
    }

    #[Pure]
    public static function missingConstant(): int
    {
        return self::MISSING; // @phpstan-ignore classConstant.notFound
    }
}
