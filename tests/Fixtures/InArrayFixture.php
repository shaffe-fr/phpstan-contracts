<?php

declare(strict_types=1);

namespace PhpstanContracts\Tests\Fixtures;

use PhpstanContracts\Attribute\Ensures;
use PhpstanContracts\Attribute\Pure;

final class InArrayFixture
{
    private const ALLOWED_CODES = ['EUR', 'USD'];

    private const LIMITS = [10, 20.5];

    #[Ensures('$result === ($n === 1 || $n === 2 || $n === 3)')]
    public static function intsCompareAsWithEquals(int $n): bool
    {
        return in_array($n, [1, 2, 3]);
    }

    #[Ensures('$result === ($code === "EUR" || $code === "USD")')]
    public static function stringsWithStrict(string $code): bool
    {
        return in_array($code, ['EUR', 'USD'], true);
    }

    #[Ensures('$result === ($code === "EUR" || $code === "USD")')]
    public static function namedArguments(string $code): bool
    {
        return in_array(haystack: self::ALLOWED_CODES, needle: $code, strict: true);
    }

    /** An int equals a float with ==, after conversion: 10 == 10.0. */
    #[Ensures('implies($n === 10, $result)')]
    #[Ensures('implies($result, $n === 10)')]
    public static function anIntEqualsAFloatWithoutStrict(int $n): bool
    {
        return in_array($n, self::LIMITS);
    }

    /** @param int<0, 100> $limit */
    #[Ensures('$result === ($n === 0 || $n === $limit + 1)')]
    public static function elementsCanBeExpressions(int $n, int $limit): bool
    {
        return in_array($n, [0, $limit + 1], true);
    }

    #[Ensures('!$result')]
    public static function anEmptyListContainsNothing(int $n): bool
    {
        return in_array($n, [], true);
    }

    #[Ensures('$result === ($status === null || $status === Suit::Hearts)')]
    public static function nullWithStrict(?Suit $status): bool
    {
        return in_array($status, [null, Suit::Hearts], true);
    }

    #[Ensures('$result')]
    public static function inAContract(int $n): bool
    {
        return $n >= 1 && $n <= 3 ? in_array($n, [1, 2, 3], true) : true;
    }

    /** @param int<0, 10> $n */
    #[Ensures('in_array($result, [0, 2, 4, 6, 8, 10, 12, 14, 16, 18, 20], true)')]
    public static function inArrayInAnEnsures(int $n): int
    {
        return $n * 2;
    }

    #[Ensures('$result === ($code === "EUR")')]
    public static function strictCatchesAMissingCode(string $code): bool
    {
        return in_array($code, ['EUR', 'USD'], true);
    }

    #[Pure]
    public static function stringsWithoutStrict(string $code): bool
    {
        return in_array($code, ['EUR', 'USD']);
    }

    #[Pure]
    public static function nullableWithoutStrict(?int $n): bool
    {
        return in_array($n, [0, 1]);
    }

    #[Pure]
    public static function strictComparisonOfDifferentTypes(int $n): bool
    {
        return in_array($n, [1, 2.0], true);
    }

    #[Pure]
    public static function keyedHaystack(int $n): bool
    {
        return in_array($n, ['a' => 1], true);
    }

    #[Pure]
    public static function strictFlagThatIsNotALiteral(int $n, bool $strict): bool
    {
        return in_array($n, [1], $strict);
    }
}
