<?php

declare(strict_types=1);

namespace PhpstanContracts\Tests\Fixtures;

use PhpstanContracts\Attribute\Pure;

final class TypeFixture
{
    /** @param list<int> $items */
    #[Pure]
    public static function arrayParameter(array $items): int
    {
        return 1;
    }

    #[Pure]
    public static function mixedParameter(mixed $value): int
    {
        return 1;
    }

    #[Pure]
    public static function unionOfDifferentTypes(int|string $value): int
    {
        return 1;
    }

    #[Pure]
    public static function intOrFloat(int|float $value): int
    {
        return 1;
    }

    #[Pure]
    public static function objectParameter(object $value): int
    {
        return 1;
    }

    #[Pure]
    public static function callableParameter(callable $value): int
    {
        return 1;
    }

    #[Pure]
    public static function closureParameter(\Closure $value): int
    {
        return 1;
    }

    #[Pure]
    public static function interfaceParameter(\Countable $value): int
    {
        return 1;
    }

    #[Pure]
    public static function voidReturn(int $n): void
    {
    }

    #[Pure]
    public static function arrayReturn(int $n): array
    {
        return [];
    }

    #[Pure]
    public static function variadicParameter(int ...$numbers): int
    {
        return 1;
    }

    #[Pure]
    public static function byReferenceParameter(int &$number): int
    {
        return 1;
    }

    #[Pure]
    public static function parameterNamedResult(int $result): int
    {
        return $result;
    }

    /** @param int<0, 10> $a */
    #[Pure]
    public static function everySupportedTypeIsAccepted(int $a, bool $b, float $c, string $d, Suit $e, Level $f, Code $g, ?int $h, ?Point $i, Point $j): int
    {
        return $a;
    }
}
