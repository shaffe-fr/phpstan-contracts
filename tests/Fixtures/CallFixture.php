<?php

declare(strict_types=1);

namespace PhpstanContracts\Tests\Fixtures;

use PhpstanContracts\Attribute\Ensures;
use PhpstanContracts\Attribute\Pure;
use PhpstanContracts\Attribute\Requires;

/**
 * Calls to methods declared in the same file.
 */
final class CallFixture
{
    /** @param int<0, 100> $x */
    #[Ensures('$result === 2 * $x + 1')]
    public static function callsAStaticHelper(int $x): int
    {
        return self::double($x) + 1;
    }

    /** @param int<0, 100> $x */
    #[Ensures('$result === 2 * $x')]
    public static function callsAHelperByTheClassName(int $x): int
    {
        return CallFixture::double($x);
    }

    #[Pure]
    public static function theHelperOverflowsForThisArgument(int $x): int
    {
        return self::double($x);
    }

    #[Ensures('$result >= 0')]
    public static function satisfiesAPrecondition(int $x): int
    {
        return $x >= 0 ? self::halve($x) : 0;
    }

    #[Pure]
    public static function violatesAPrecondition(int $x): int
    {
        return self::halve($x);
    }

    #[Ensures('$result === 15')]
    public static function usesADefaultValue(): int
    {
        return self::withDefault() + self::withDefault(10);
    }

    #[Ensures('$result === 7')]
    public static function namedArguments(): int
    {
        return self::difference(b: 3, a: 10);
    }

    /** @param int<0, 10> $x */
    #[Ensures('$result === (float) $x * 0.5')]
    public static function anIntArgumentForAFloatParameter(int $x): float
    {
        return self::half($x);
    }

    /** @param int<-10, 10> $x */
    #[Ensures('$result === abs($x)')]
    public static function aHelperWithSeveralReturns(int $x): int
    {
        return self::magnitude($x);
    }

    #[Ensures('$result === ($point->x > 0)')]
    public static function callsAHelperWithAnObject(Point $point): bool
    {
        return self::isRight($point);
    }

    #[Ensures('$result === 0')]
    public static function contractOfTheCalleeIsNotNeeded(): int
    {
        return self::wrongContract();
    }

    #[Pure]
    public static function recursion(int $n): int
    {
        return $n > 0 ? self::recursion($n - 1) : 0;
    }

    #[Pure]
    public static function callsAMethodInAnotherFile(Point $point): int
    {
        return $point->sum();
    }

    #[Pure]
    public static function callsAMethodThatCanBeOverridden(OpenShape $shape): int
    {
        return $shape->sides();
    }

    #[Pure]
    public static function callsAMethodThatIsNotVerifiable(int $n): int
    {
        return self::loops($n);
    }

    #[Pure]
    public static function callsWithATypeThatDoesNotFit(bool $flag): int
    {
        return self::double($flag);
    }

    #[Ensures('$result === self::double(1)')]
    public static function callInAContract(): int
    {
        return 2;
    }

    private static function double(int $x): int
    {
        return $x * 2;
    }

    #[Requires('$x >= 0')]
    #[Ensures('$result >= 0')]
    private static function halve(int $x): int
    {
        return intdiv($x, 2);
    }

    private static function withDefault(int $x = 5): int
    {
        return $x;
    }

    private static function difference(int $a, int $b): int
    {
        return $a - $b;
    }

    private static function half(float $value): float
    {
        return $value * 0.5;
    }

    /** @param int<-10, 10> $x */
    private static function magnitude(int $x): int
    {
        if ($x < 0) {
            return -$x;
        }

        return $x;
    }

    private static function isRight(Point $point): bool
    {
        return $point->x > 0;
    }

    /**
     * Its contract is wrong, and reported as such, but a caller only depends on what it returns.
     */
    #[Ensures('$result === 1')]
    private static function wrongContract(): int
    {
        return 0;
    }

    private static function loops(int $n): int
    {
        while ($n > 0) {
            $n -= 1;
        }

        return $n;
    }
}

readonly class OpenShape
{
    public function __construct(public int $corners)
    {
    }

    public function sides(): int
    {
        return $this->corners;
    }
}
