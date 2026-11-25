<?php

declare(strict_types=1);

namespace PhpstanContracts\Tests\Rule;

use PhpstanContracts\Tests\Fixtures\FloatFixture;
use PhpstanContracts\Tests\Fixtures\PhpDocFloat;
use PhpstanContracts\Tests\Support\RuleFixtureTestCase;

final class FloatRuleTest extends RuleFixtureTestCase
{
    public function testFloatRules(): void
    {
        $this->assertOutcomes([
            FloatFixture::class => [
                'negationIsItsOwnInverse' => [],
                'additionIsCommutative' => [],
                'absoluteValueOfANumber' => [],
                'floorIsNotAboveTheValue' => [],
                'ceilIsNotBelowTheValue' => [],
                'squareRootOfANonNegative' => [],
                'integerSurvivesAConversionToFloatAndBack' => [],
                'anIntegerIsConvertedWhenMixedWithAFloat' => [],
                'infinityConstant' => [],
                'nanIsNotIdenticalToItself' => [],
                'negativeZeroIsIdenticalToZero' => [],

                'oneTenthPlusTwoTenthsIsNotThreeTenths' => ['contracts.ensuresViolated: $result = 0.30000000000000004'],
                'absoluteValueOfNanIsNotNonNegative' => ['contracts.ensuresViolated: $x = NAN => $result = NAN'],
                'additionIsNotAssociative' => ['contracts.ensuresViolated: $result === $a + ($b + $c)'],
                'floorOfAPriceInCents' => ['contracts.ensuresViolated: $result = 1998'],

                'floatDivisionByZero' => ['contracts.divisionByZero: `$a / $b`'],
                'castOfNanOrOutOfRange' => ['contracts.invalidCast: `(int) $x` loses the value for NaN, infinities and values outside the int range: PHP 8.3 and 8.4 wrap it or return 0, and PHP 8.5 also raises a warning.'],

                'roundIsRejected' => ['contracts.unsupported: Call to round()'],
                'aFloatIsNeverIdenticalToAnInt' => ['contracts.unsupported: always false in PHP'],
                'minOfFloatsIsRejected' => ['contracts.unsupported: min() and max() are only supported on ints'],
                'moduloOfFloatsIsRejected' => ['contracts.unsupported: converts its operands to int'],
                'dividingTwoIntegersIsRejected' => ['contracts.unsupported: use intdiv()'],
            ],
        ]);
    }

    /**
     * An int passed where only the PHPDoc says float stays an int, so the model of a float would be wrong.
     */
    public function testAFloatIsOnlyModelledWhereTheNativeTypeConverts(): void
    {
        $this->assertOutcomes([
            PhpDocFloat::class => [
                'untypedParameter' => ['contracts.unsupported: Parameter $x has the PHPDoc type float but the native type mixed, which is what PHP converts its argument to: an int argument stays an int. Declare float in the signature.'],
                'mixedParameter' => ['contracts.unsupported: Parameter $x has the PHPDoc type float but the native type mixed'],
                'mixedField' => ['contracts.unsupported: Type PhpstanContracts\Tests\Fixtures\MixedMeasure is not supported: only enums, readonly data classes, lists, array shapes with string keys and scalars are. None of the public readonly properties of PhpstanContracts\Tests\Fixtures\MixedMeasure can be modelled: $amount (float): Property PhpstanContracts\Tests\Fixtures\MixedMeasure::$amount cannot be read in a verified method: its PHPDoc type is float but its native type is mixed, so an int assigned to it stays an int. Declare float natively'],
                'nativeNullableFloat' => [],
                'nativeFloat' => [],
            ],
        ]);
    }
}
