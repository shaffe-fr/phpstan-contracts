<?php

declare(strict_types=1);

namespace PhpstanContracts\Tests\Fixtures\CrossFile;

use PhpstanContracts\Attribute\Ensures;
use PhpstanContracts\Attribute\Pure;
use PhpstanContracts\Attribute\Requires;
use PhpstanContracts\Attribute\Throws;
use PhpstanContracts\Tests\Fixtures\ConstantInitializers;
use PhpstanContracts\Tests\Fixtures\DeprecatedLimits;
use PhpstanContracts\Tests\Fixtures\FloatLabel;
use PhpstanContracts\Tests\Fixtures\Mode;

/**
 * Calls to Callees, in another file, next to the same code written here: the loops, the budget,
 * the exception factories and the constant guards must treat both alike.
 */
final class CrossFileInteractions
{
    /**
     * @param int<0, 10000000> $totalDiscount
     * @param non-empty-list<int<0, 10000000>> $lineTotals
     * @return list<int>
     */
    #[Requires('count($lineTotals) <= 8')]
    #[Requires('array_sum($lineTotals) >= 1')]
    #[Ensures('count($result) === count($lineTotals)')]
    public static function costlySplitInACallee(int $totalDiscount, array $lineTotals): array
    {
        return Callees::split($totalDiscount, $lineTotals);
    }

    /** @param list<int<0, 1000>> $cents */
    #[Requires('count($cents) <= 3')]
    #[Ensures('$result >= 0 && $result <= 3000')]
    public static function boundedLoopInACallee(array $cents): int
    {
        return Callees::boundedSum($cents);
    }

    /** @param list<int<0, 1000>> $cents */
    #[Ensures('$result >= 0')]
    public static function unboundedLoopInACallee(array $cents): int
    {
        return Callees::unboundedSum($cents);
    }

    #[Requires('$perPage >= 1 && $perPage <= 1000 && $price >= 0 && $price <= 1000000')]
    #[Ensures('$result % $perPage === 0')]
    public static function nonLinearCallee(int $price, int $perPage): int
    {
        return Callees::roundDown($price, $perPage);
    }

    #[Throws(NegativeRefusal::class, when: '$x < 0')]
    #[Ensures('$result === $x')]
    public static function factoryOfAnotherFile(int $x): int
    {
        if ($x < 0) {
            throw Refusals::negative();
        }

        return $x;
    }

    #[Throws(NegativeRefusal::class, when: '$x < 0')]
    #[Ensures('$result === $x')]
    public static function calleeThrowsThroughAFactory(int $x): int
    {
        return Callees::guard($x);
    }

    #[Pure]
    public static function calleeThrowsThroughAFactoryUndeclared(int $x): int
    {
        return Callees::guard($x);
    }

    #[Throws(NegativeRefusal::class, when: '$x < -1')]
    public static function calleeThrowsThroughAFactoryTooNarrow(int $x): int
    {
        return Callees::guard($x);
    }

    // OpenRefusal is not final: the object can be a NarrowRefusal, which has its own condition.
    #[Throws(OpenRefusal::class)]
    #[Throws(NarrowRefusal::class, when: '$x < -10')]
    public static function nonFinalFactoryHere(int $x): int
    {
        if ($x < 0) {
            throw Refusals::open();
        }

        return $x;
    }

    #[Throws(OpenRefusal::class)]
    #[Throws(NarrowRefusal::class, when: '$x < -10')]
    public static function nonFinalFactoryInACallee(int $x): int
    {
        return Callees::guardOpen($x);
    }

    #[Ensures('$result === 10')]
    public static function deprecatedConstantHere(): int
    {
        return DeprecatedLimits::LIMIT;
    }

    #[Ensures('$result === 10')]
    public static function deprecatedConstantInACallee(): int
    {
        return Callees::deprecatedConstant();
    }

    #[Ensures('$result === Mode::Legacy')]
    public static function deprecatedCaseHere(): Mode
    {
        return Mode::Legacy;
    }

    #[Ensures('$result === Mode::Legacy')]
    public static function deprecatedCaseInACallee(): Mode
    {
        return Callees::deprecatedCase();
    }

    #[Pure]
    public static function floatLabelHere(): string
    {
        return ConstantInitializers::LABEL;
    }

    #[Pure]
    public static function floatLabelInACallee(): string
    {
        return Callees::floatLabel();
    }

    #[Throws(\ValueError::class)]
    public static function floatBackingHere(string $label): FloatLabel
    {
        return FloatLabel::from($label);
    }

    #[Throws(\ValueError::class)]
    public static function floatBackingInACallee(string $label): FloatLabel
    {
        return Callees::floatBacking($label);
    }

    /** @param int<0, DeprecatedLimits::DERIVED> $x */
    #[Ensures('$result <= 11')]
    public static function constantBoundHere(int $x): int
    {
        return $x;
    }

    /** @param int<0, DeprecatedLimits::DERIVED> $x */
    #[Ensures('$result <= 11')]
    public static function constantBoundInACallee(int $x): int
    {
        return Callees::boundedByAConstant($x);
    }
}
