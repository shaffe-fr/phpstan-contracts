<?php

declare(strict_types=1);

namespace PhpstanContracts\Tests\Fixtures;

use PhpstanContracts\Attribute\Ensures;
use PhpstanContracts\Attribute\Pure;

final class DeprecatedLimits
{
    #[\Deprecated]
    public const LIMIT = 10;

    public const DERIVED = self::LIMIT + 1;

    public const CURRENT = 20;
}

enum Mode: string
{
    case Current = 'current';

    #[\Deprecated]
    case Legacy = 'legacy';
}

enum FloatLabel: string
{
    case Third = '' . 1 / 3;
    case Half = 'half';
}

/**
 * PHPStan folds a constant in its own process. The folded value is wrong when reading
 * the constant triggers a deprecation (from PHP 8.4 on, the error handler runs and may
 * throw), or when the initializer turns a float into a string, whose digits depend on
 * the `precision` ini setting.
 */
final class ConstantInitializers
{
    public const LABEL = 'v' . (0.1 + 0.2);

    public const THIRD = '' . 1 / 3;

    public const LABELS = ['v' . 0.5, 'w'];

    public const NUMBERED = 'v' . 3;

    #[Ensures('$result === 10')]
    public static function readsADeprecatedConstant(): int
    {
        return DeprecatedLimits::LIMIT;
    }

    #[Ensures('$result === 11')]
    public static function readsAConstantBuiltFromADeprecatedOne(): int
    {
        return DeprecatedLimits::DERIVED;
    }

    #[Ensures('$result === 20')]
    public static function readsAConstant(): int
    {
        return DeprecatedLimits::CURRENT;
    }

    #[Ensures('$result === Mode::Legacy')]
    public static function readsADeprecatedCase(): Mode
    {
        return Mode::Legacy;
    }

    #[Pure]
    public static function matchesADeprecatedCase(Mode $mode): int
    {
        return match ($mode) {
            Mode::Current => 1,
            Mode::Legacy => 2,
        };
    }

    #[Ensures('$result === Mode::Current')]
    public static function readsACase(): Mode
    {
        return Mode::Current;
    }

    #[Ensures('$result === "v0.3"')]
    public static function concatenatesAFloatInAConstant(): string
    {
        return self::LABEL;
    }

    #[Ensures('$result === "0.33333333333333"')]
    public static function concatenatesADivisionInAConstant(): string
    {
        return self::THIRD;
    }

    #[Pure]
    public static function searchesAListWithAFloatLabel(string $label): bool
    {
        return in_array($label, self::LABELS, true);
    }

    #[Ensures('$result === "v3"')]
    public static function concatenatesAnIntInAConstant(): string
    {
        return self::NUMBERED;
    }

    #[Pure]
    public static function readsTheValueOfAnEnumWithAFloatLabel(FloatLabel $label): string
    {
        return $label->value;
    }
}
