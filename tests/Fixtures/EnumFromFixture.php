<?php

declare(strict_types=1);

namespace PhpstanContracts\Tests\Fixtures;

use PhpstanContracts\Attribute\Ensures;
use PhpstanContracts\Attribute\Pure;

/** A pure enum has no built-in from(), so it may declare its own. */
enum Rank
{
    case Low;
    case High;

    public static function from(int $value): self
    {
        return $value > 5 ? self::High : self::Low;
    }
}

enum Flag
{
    case On;
    case Off;
}

enum Limit: int
{
    case Small = 1;
    case Huge = PHP_INT_MAX;
}

final class EnumFromFixture
{
    #[Ensures('$result === Rank::High')]
    public static function callsTheFromOfAPureEnum(): Rank
    {
        return Rank::from(9);
    }

    #[Pure]
    public static function fromOfAPureEnumWithoutOne(int $value): Flag
    {
        return Flag::from($value);
    }

    #[Pure]
    public static function tryFromOfAnEnumWithAnUnfoldedValue(int $value): ?Limit
    {
        return Limit::tryFrom($value);
    }

    #[Pure]
    public static function valueOfAnEnumWithAnUnfoldedValue(Limit $limit): int
    {
        return $limit->value;
    }

    #[Ensures('$result === Limit::Small')]
    public static function caseOfAnEnumWithAnUnfoldedValue(): Limit
    {
        return Limit::Small;
    }
}
