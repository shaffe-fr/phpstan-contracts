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

enum GlobalLabel: string
{
    case Printed = PRINTED;
    case Plain = 'plain';
}

/**
 * A global constant is evaluated where it is defined, so its initializer is checked for a
 * float turned into a string, like the initializer that reads it. The constants are in
 * GlobalConstants.php and RuntimeConstants.php.
 */
final class GlobalConstantInitializers
{
    public const LABEL = PRINTED;

    public const SUFFIXED = PRINTED . '';

    public const SHORT = PRINTS_SHORT;

    public const ORDERED = ORDERS_A_PRINTED_FLOAT;

    public const MASKED = MASKS_A_FLOAT;

    public const DEFINED = \PRINTED_BY_DEFINE;

    public const WEEK = SEVEN;

    public const PREFIX = NUMBERED_PREFIX;

    public const RUNTIME = DEFINED_AT_RUNTIME;

    #[Ensures('$result === "v0.3"')]
    public static function readsAGlobalConstantThatPrintsAFloat(): string
    {
        return self::LABEL;
    }

    #[Ensures('$result === "v0.3"')]
    public static function concatenatesAGlobalConstantThatPrintsAFloat(): string
    {
        return self::SUFFIXED;
    }

    #[Ensures('$result === true')]
    public static function readsABoolThatComparesAPrintedFloat(): bool
    {
        return self::SHORT;
    }

    #[Ensures('$result === true')]
    public static function readsABoolThatOrdersAFloatAndAString(): bool
    {
        return self::ORDERED;
    }

    // The deprecation of 1.5 | 0 is triggered where MASKS_A_FLOAT is defined, not here.
    #[Ensures('$result === 1')]
    public static function readsAGlobalConstantThatMasksAFloat(): int
    {
        return self::MASKED;
    }

    #[Ensures('$result === "v0.3"')]
    public static function readsAConstantDefinedByDefine(): string
    {
        return self::DEFINED;
    }

    #[Pure]
    public static function readsTheValueOfAnEnumCaseSetByAGlobalConstant(GlobalLabel $label): string
    {
        return $label->value;
    }

    #[Ensures('$result === 7')]
    public static function readsAGlobalIntConstant(): int
    {
        return self::WEEK;
    }

    #[Ensures('$result === "v7"')]
    public static function readsAGlobalConstantThatPrintsAnInt(): string
    {
        return self::PREFIX;
    }

    #[Ensures('$result === "v3"')]
    public static function readsAConstantOnlyKnownAtRuntime(): string
    {
        return self::RUNTIME;
    }

    /**
     * @param DEFINED_AT_RUNTIME $label
     */
    #[Ensures('$result === $label')]
    public static function typesAParameterWithAConstantOnlyKnownAtRuntime(string $label): string
    {
        return $label;
    }
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

    // PHP 8 orders a float and a non-numeric string by printing the float: true at precision 14, false at -1.
    public const ORDERED = (0.1 + 0.2) < '0.3!';

    public const SORTED = (0.1 + 0.2) <=> '0.3!';

    public const HALF_BELOW_ONE = 0.5 < 1;

    // A printed finite float is a numeric string, so it never equals '0.3!', at any precision.
    public const EQUAL = (0.1 + 0.2) == '0.3!';

    // From PHP 8.1 on, a fractional float converted to an int implicitly runs the error handler.
    public const MASKED = 1.5 | 0;

    public const NAMES = ['a', 'b'];

    public const SECOND = self::NAMES[1.5];

    public const MASK = 6 | 1;

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

    #[Ensures('$result === true')]
    public static function ordersAFloatAndAStringInAConstant(): bool
    {
        return self::ORDERED;
    }

    #[Ensures('$result === -1')]
    public static function comparesAFloatAndAStringWithTheSpaceshipInAConstant(): int
    {
        return self::SORTED;
    }

    #[Ensures('$result === true')]
    public static function ordersAFloatAndAnIntInAConstant(): bool
    {
        return self::HALF_BELOW_ONE;
    }

    #[Ensures('$result === false')]
    public static function comparesAFloatAndAStringForEqualityInAConstant(): bool
    {
        return self::EQUAL;
    }

    #[Ensures('$result === 1')]
    public static function masksAFloatInAConstant(): int
    {
        return self::MASKED;
    }

    #[Ensures('$result === "b"')]
    public static function indexesWithAFloatInAConstant(): string
    {
        return self::SECOND;
    }

    #[Ensures('$result === 7')]
    public static function masksAnIntInAConstant(): int
    {
        return self::MASK;
    }

    #[Pure]
    public static function readsTheValueOfAnEnumWithAFloatLabel(FloatLabel $label): string
    {
        return $label->value;
    }
}
