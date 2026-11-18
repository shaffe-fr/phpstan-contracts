<?php

declare(strict_types=1);

namespace PhpstanContracts\Examples\RealWorld;

use PhpstanContracts\Attribute\Ensures;
use PhpstanContracts\Attribute\Pure;

final class CurrencyParser
{
    /**
     * Currency from an untrusted query-string value, EUR when it is unknown.
     */
    #[Ensures('implies($code === "USD", $result === Currency::Usd)')]
    #[Ensures('implies($code === "GBP", $result === Currency::Gbp)')]
    #[Ensures('implies($code !== "EUR" && $code !== "USD" && $code !== "GBP", $result === Currency::Eur)')]
    public static function fromQuery(string $code): Currency
    {
        return Currency::tryFrom($code) ?? Currency::Eur;
    }

    /**
     * Currency::from() throws on anything that is not a backing value.
     */
    #[Pure]
    public static function fromQueryBroken(string $code): Currency
    {
        return Currency::from($code);
    }

    /**
     * Round trip: the backing value of a currency parses back to the same currency.
     */
    #[Ensures('$result === $currency')]
    public static function roundTrip(Currency $currency): Currency
    {
        return Currency::from($currency->value);
    }

    /**
     * strlen() counts bytes: "€" takes 3, "£" takes 2 and "$" takes 1.
     */
    #[Ensures('strlen($result) >= 1 && strlen($result) <= 3')]
    #[Ensures('implies($currency === Currency::Usd, strlen($result) === 1)')]
    public static function symbol(Currency $currency): string
    {
        return match ($currency) {
            Currency::Eur => '€',
            Currency::Usd => '$',
            Currency::Gbp => '£',
        };
    }

    /**
     * Assumes one character is one byte, which is not true of "€" and "£".
     */
    #[Ensures('strlen($result) === 1')]
    public static function symbolBroken(Currency $currency): string
    {
        return match ($currency) {
            Currency::Eur => '€',
            Currency::Usd => '$',
            Currency::Gbp => '£',
        };
    }
}
