<?php

declare(strict_types=1);

namespace PhpstanContracts\Examples;

use PhpstanContracts\Attribute\Ensures;
use PhpstanContracts\Attribute\Pure;
use PhpstanContracts\Attribute\Requires;
use PhpstanContracts\Examples\RealWorld\PriceContext;

/**
 * Each method here is expected to be reported by the extension.
 */
final class Rejected
{
    /** Overflow: nothing bounds the operands. */
    #[Pure]
    public static function unboundedProduct(int $price, int $quantity): int
    {
        return $price * $quantity;
    }

    /** Division by zero: the guard only covers the first branch. */
    #[Ensures('$result >= 0')]
    public static function unguardedDivision(int $total, int $parts): int
    {
        if ($parts > 0 && intdiv($total, $parts) > 3) {
            return 1;
        }

        return intdiv($total, $parts);
    }

    /** Guarded division: proven, the && short-circuit protects the divisor. */
    #[Requires('$total >= 0 && $total <= 1000000')]
    #[Ensures('$result >= 0')]
    public static function guardedDivision(int $total, int $parts): int
    {
        if ($parts > 0 && intdiv($total, $parts) > 3) {
            return 1;
        }

        return 0;
    }

    /** Contradictory preconditions make any proof vacuous. */
    #[Requires('$quantity > 10')]
    #[Requires('$quantity < 5')]
    #[Ensures('$result === 42')]
    public static function contradictory(int $quantity): int
    {
        return $quantity;
    }

    /** Not every path returns. */
    #[Pure]
    public static function missingReturn(int $quantity): int
    {
        if ($quantity > 0) {
            return 1;
        }

        $copy = $quantity;
    }

    /** Unhandled match: the arms do not cover every int. */
    #[Pure]
    public static function partialMatch(int $quantity): int
    {
        return match ($quantity) {
            0 => 10,
            1 => 20,
        };
    }

    /** Loops are rejected. */
    #[Pure]
    public static function loop(int $count): int
    {
        $sum = 0;
        while ($count > 0) {
            $sum += $count;
            --$count;
        }

        return $sum;
    }

    /** round() is rejected: its result changed between PHP versions. */
    #[Pure]
    public static function rounding(float $amount): float
    {
        return round($amount);
    }

    /**
     * Arrays are rejected.
     *
     * @param list<int> $items
     */
    #[Pure]
    public static function array(array $items): int
    {
        return 1;
    }

    /** Side effects are rejected. */
    #[Pure]
    public static function effect(int $quantity): int
    {
        echo $quantity;

        return $quantity;
    }

    /** A constructor with logic cannot be modelled, so the class cannot be built in a verified method. */
    #[Pure]
    public static function validatedConstructor(int $value): int
    {
        $percentage = new Percentage($value);

        return $value;
    }

    /** A class with a mutable property is rejected. */
    #[Pure]
    public static function mutableObject(Counter $counter): int
    {
        return 1;
    }

    /** A data class is modelled by its public readonly properties: the mutable state of its base class cannot be read. */
    #[Pure]
    public static function frameworkState(PriceContext $context): bool
    {
        return $context->additional === [];
    }

    /** A method of another file is not translated: the result cache would not redo the proof when its body changes. */
    #[Ensures('$result >= 0')]
    public static function callToAnotherFile(PriceContext $context): int
    {
        return $context->discountPercent();
    }

    /** Dynamic access is rejected. */
    #[Pure]
    public static function dynamic(object $object, string $name): int
    {
        return $object->$name;
    }

    /** Syntax error in the contract. */
    #[Ensures('$result >=')]
    public static function brokenContract(int $quantity): int
    {
        return $quantity;
    }
}
