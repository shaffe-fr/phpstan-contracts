<?php

declare(strict_types=1);

namespace PhpstanContracts\Tests\Fixtures\CrossFile;

use PhpstanContracts\Attribute\Ensures;
use PhpstanContracts\Attribute\Pure;
use PhpstanContracts\Attribute\Requires;

/**
 * Calls to methods, and `new` of classes, declared in the other files of this directory.
 */
final class CrossFileFixture
{
    #[Requires('$cents >= 0 && $cents <= 1000000')]
    #[Ensures('$result >= $cents')]
    public static function provenCall(Tariff $tariff, int $cents): int
    {
        return $tariff->withSurcharge($cents);
    }

    #[Requires('$cents >= 0 && $cents <= 1000000')]
    #[Ensures('$result === $cents')]
    public static function refutedCall(Tariff $tariff, int $cents): int
    {
        return $tariff->withSurcharge($cents);
    }

    #[Pure]
    public static function violatesThePreconditionOfTheCallee(Tariff $tariff, int $cents): int
    {
        return $tariff->withSurcharge($cents);
    }

    #[Pure]
    public static function overflowsInTheCallee(Tariff $tariff, int $factor): int
    {
        return $tariff->scaled($factor);
    }

    #[Ensures('$result >= 0 && $result <= 2000000')]
    public static function chainOverThreeFiles(int $cents): int
    {
        return Steps::doubledClamp($cents);
    }

    #[Ensures('$result <= 1000000')]
    public static function chainOverThreeFilesBroken(int $cents): int
    {
        return Steps::doubledClamp($cents);
    }

    #[Requires('$percent >= 0 && $percent <= 100')]
    #[Ensures('$result->percent === $percent && $result->baseCents === 0')]
    public static function buildsAClassOfAnotherFile(int $percent): Tariff
    {
        return new Tariff(0, $percent);
    }

    #[Pure]
    public static function buildsAClassWhoseConstructorHasLogic(int $count): int
    {
        return (new Busy($count))->count;
    }

    #[Pure]
    public static function buildsAClassWhoseParentHasADestructor(int $totalCents): int
    {
        return (new Stamped($totalCents))->totalCents;
    }

    #[Pure]
    public static function callsAMethodThatCanBeOverridden(OpenRate $rate): int
    {
        return $rate->percent();
    }

    #[Requires('$cents >= 0 && $cents <= 1000000')]
    #[Ensures('$result >= $cents')]
    public static function callsANonStrictFile(TariffNonStrict $tariff, int $cents): int
    {
        return $tariff->withSurcharge($cents);
    }

    #[Pure]
    public static function aNonStrictCalleeCannotRelyOnCoercion(float $amount): int
    {
        return TariffNonStrict::clampOf($amount);
    }

    #[Pure]
    public static function aChainDeeperThanTheLimit(int $x): int
    {
        return Chain::depth1($x);
    }

    #[Pure]
    public static function moreBodiesThanTheLimit(int $x): int
    {
        return Chain::level1($x);
    }

    // PHP returns 2: the body that PHPStan reflects is not the one that runs.
    #[Ensures('$result === 1')]
    public static function callsAClassDeclaredTwice(): int
    {
        return Twice::value();
    }

    #[Ensures('$result->amount === $amount')]
    public static function buildsAClassDeclaredTwice(int $amount): TwiceData
    {
        return new TwiceData($amount);
    }

    // PHP returns 2: the second declaration of TwiceKid overrides the method of TwiceBase.
    #[Ensures('$result === 1')]
    public static function callsThroughASubclassDeclaredTwice(): int
    {
        return TwiceKid::value();
    }

    // PHP returns 2: the second declaration of TwiceDataKid overrides the method of TwiceDataBase.
    #[Ensures('$result === 1')]
    public static function callsAnInheritedMethodOfADataClassDeclaredTwice(TwiceDataKid $kid): int
    {
        return $kid->amount();
    }

    // PHP throws for a negative amount: the second declaration of TwiceMiddle has its own constructor.
    #[Ensures('$result->amount === $amount')]
    public static function buildsAClassWhoseParentIsDeclaredTwice(int $amount): TwiceLeaf
    {
        return new TwiceLeaf($amount);
    }

    #[Ensures('$result === 2')]
    public static function callsAClassDeclaredOnceInAnIf(): int
    {
        return Once::value();
    }

    #[Ensures('$result->amount === $amount')]
    public static function buildsAClassDeclaredOnceInAnIf(int $amount): Once
    {
        return new Once($amount);
    }
}
