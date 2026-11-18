<?php

declare(strict_types=1);

namespace PhpstanContracts\Tests\Fixtures;

use PhpstanContracts\Attribute\Ensures;
use PhpstanContracts\Attribute\Pure;

final class StringFixture
{
    #[Ensures('strlen($result) === strlen($a) + strlen($b)')]
    #[Ensures('str_starts_with($result, $a)')]
    #[Ensures('str_ends_with($result, $b)')]
    public static function concatenation(string $a, string $b): string
    {
        return $a . $b;
    }

    #[Ensures('implies($result, strlen($text) >= strlen($prefix))')]
    public static function aPrefixIsNotLongerThanTheText(string $text, string $prefix): bool
    {
        return str_starts_with($text, $prefix);
    }

    #[Ensures('$result === true')]
    public static function everyStringContainsTheEmptyString(string $text): bool
    {
        return str_contains($text, '');
    }

    #[Ensures('strlen($result) === 3')]
    public static function theEuroSignIsThreeBytes(): string
    {
        return '€';
    }

    #[Ensures('strlen($result) === 6 && $result === "a\"b\\c\0"')]
    public static function literalsKeepQuotesBackslashesAndNulBytes(): string
    {
        return "a\"b\\c\0";
    }

    #[Ensures('$result === "café" && strlen($result) === 5')]
    public static function nonAsciiLiteralsAreTheirUtf8Bytes(): string
    {
        return 'café';
    }

    /** @param non-empty-string $text */
    #[Ensures('$result > 0')]
    public static function nonEmptyStringHasPositiveLength(string $text): int
    {
        return strlen($text);
    }

    #[Ensures('implies($result, strlen($a) === strlen($b))')]
    public static function identicalStringsHaveTheSameLength(string $a, string $b): bool
    {
        return $a === $b;
    }

    #[Ensures('strlen($result) === strlen($a) + 1')]
    public static function compoundConcatenation(string $a): string
    {
        $text = $a;
        $text .= '!';

        return $text;
    }

    #[Ensures('$result === "ab" . "c"')]
    public static function literalsConcatenate(): string
    {
        return 'a' . 'bc';
    }

    #[Ensures('implies($result, str_ends_with($file, ".png"))')]
    public static function containsIsNotEndsWith(string $file): bool
    {
        return str_contains($file, '.png');
    }

    #[Ensures('strlen($result) === 1')]
    public static function oneCharacterIsNotOneByte(): string
    {
        return '€';
    }

    #[Pure]
    public static function looseEqualityOfStringsIsRejected(string $a): bool
    {
        return $a == 'x';
    }

    #[Pure]
    public static function substrIsNotSupported(string $a): string
    {
        return substr($a, 1);
    }

    #[Pure]
    public static function interpolationIsRejected(string $a): string
    {
        return "value: {$a}";
    }

    #[Pure]
    public static function concatenatingAnIntegerIsRejected(string $a, int $n): string
    {
        return $a . $n;
    }

    #[Pure]
    public static function orderingStringsIsRejected(string $a, string $b): bool
    {
        return $a < $b;
    }
}
