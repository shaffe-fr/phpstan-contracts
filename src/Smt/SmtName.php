<?php

declare(strict_types=1);

namespace PhpstanContracts\Smt;

/**
 * Turns a PHP class, case or property name into a fragment of an SMT symbol, so that
 * two different PHP names never give the same symbol: `Order\Status` and `Order_Status`
 * are two enums, and so are `A_B::C` and `A::B_C`.
 *
 * Letters, digits and `_` are kept. `\` becomes `.` and any other byte `~` followed by
 * two hex digits: PHP names never contain `.` or `~`, so the mapping can be reversed.
 * Symbols join two fragments with `/`, which no fragment contains either.
 */
final class SmtName
{
    public const SEPARATOR = '/';

    public static function of(string $phpName): string
    {
        return (string) preg_replace_callback(
            '/[^A-Za-z0-9_]/',
            static fn (array $match): string => $match[0] === '\\' ? '.' : sprintf('~%02X', ord($match[0])),
            $phpName,
        );
    }
}
