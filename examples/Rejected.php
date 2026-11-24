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

    /** A while loop is rejected: it needs a termination argument, which foreach over a list does not. */
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
     * Arrays with arbitrary keys are rejected: use a list<T> or an array shape.
     *
     * @param array<string, int> $items
     */
    #[Pure]
    public static function array(array $items): int
    {
        return 1;
    }

    /**
     * == on arrays juggles the type of every element ([1] == [true]), so it is rejected.
     *
     * @param list<int> $left
     * @param list<int> $right
     */
    #[Pure]
    public static function looseArrayEquality(array $left, array $right): bool
    {
        return $left == $right;
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

    /** Without strict: true, PHP compares strings with ==, so "1e1" matches "10". */
    #[Pure]
    public static function looseInArray(string $code): bool
    {
        return in_array($code, ['10', '20']);
    }

    /** No #[Throws] declares this exception, and the input reaches it. */
    #[Pure]
    public static function undeclaredThrow(int $x): int
    {
        if ($x < 0) {
            throw new \InvalidArgumentException('Negative.');
        }
        return $x;
    }

    /** Receipt is declared in RealWorld/Checkout.php: the result cache would not redo the proof when its constructor body changes. */
    #[Pure]
    public static function objectOfAnotherFile(): int
    {
        $receipt = new RealWorld\Receipt(0, 0, false);
        return $receipt->totalCents;
    }
}

/** Declared in this file, so that its constructor is read in the file that builds it. */
final readonly class Percentage
{
    public function __construct(public int $value)
    {
        if ($value < 0 || $value > 100) {
            throw new \InvalidArgumentException('A percentage is between 0 and 100.');
        }
    }
}
