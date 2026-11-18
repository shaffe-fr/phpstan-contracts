<?php

declare(strict_types=1);

namespace PhpstanContracts\Tests\Fixtures;

use PhpstanContracts\Attribute\Ensures;
use PhpstanContracts\Attribute\Pure;

final class EnumFixture
{
    #[Ensures('$result >= 0 && $result <= 2')]
    #[Ensures('implies($suit === Suit::Clubs, $result === 2)')]
    public static function exhaustiveMatchOnAPureEnum(Suit $suit): int
    {
        return match ($suit) {
            Suit::Hearts => 0,
            Suit::Spades => 1,
            Suit::Clubs => 2,
        };
    }

    #[Ensures('implies($result, $a === $b)')]
    #[Ensures('implies($a === $b, $result)')]
    public static function enumIdentity(Suit $a, Suit $b): bool
    {
        return $a === $b;
    }

    #[Ensures('$result === $level->value')]
    public static function matchAgreesWithTheIntBackingValue(Level $level): int
    {
        return match ($level) {
            Level::Low => 1,
            Level::Medium => 5,
            Level::High => 10,
        };
    }

    #[Ensures('strlen($result) >= 1 && strlen($result) <= 3')]
    #[Ensures('implies($code === Code::Beta, $result === "bb")')]
    public static function stringBackingValue(Code $code): string
    {
        return $code->value;
    }

    #[Ensures('strlen($result) >= 4')]
    #[Ensures('implies($suit === Suit::Clubs, $result === "Clubs")')]
    public static function caseName(Suit $suit): string
    {
        return $suit->name;
    }

    #[Ensures('$result === $code')]
    public static function fromTheValueOfACaseGivesTheCase(Code $code): Code
    {
        return Code::from($code->value);
    }

    #[Ensures('$result === Level::Medium')]
    public static function fromAnIntegerLiteral(): Level
    {
        return Level::from(5);
    }

    #[Ensures('implies($raw === "a", $result === Code::Alpha)')]
    #[Ensures('implies($raw === "zzz", $result === null)')]
    public static function tryFromAnUnknownValueIsNull(string $raw): ?Code
    {
        return Code::tryFrom($raw);
    }

    /** @param int<0, 20> $n */
    #[Ensures('implies($n === 10, $result === Level::High)')]
    #[Ensures('implies($n === 7, $result === Level::Low)')]
    public static function tryFromWithADefault(int $n): Level
    {
        return Level::tryFrom($n) ?? Level::Low;
    }

    #[Ensures('$result === \PhpstanContracts\Tests\Fixtures\Suit::Hearts')]
    public static function fullyQualifiedNamesAreResolvedInContracts(): Suit
    {
        return Suit::Hearts;
    }

    #[Pure]
    public static function matchWithAMissingCase(Suit $suit): int
    {
        return match ($suit) {
            Suit::Hearts => 0,
            Suit::Spades => 1,
        };
    }

    #[Pure]
    public static function fromAnUntrustedString(string $raw): Code
    {
        return Code::from($raw);
    }

    #[Pure]
    public static function fromAnUnboundedInteger(int $n): Level
    {
        return Level::from($n);
    }

    #[Pure]
    public static function valueOfAPureEnumDoesNotExist(Suit $suit): int
    {
        return $suit->value;
    }

    #[Pure]
    public static function casesIsNotSupported(): int
    {
        return count(Suit::cases());
    }

    #[Pure]
    public static function tryFromNeedsTheBackingType(int $n): ?Code
    {
        return Code::tryFrom($n);
    }
}
