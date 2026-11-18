<?php

declare(strict_types=1);

namespace PhpstanContracts\Tests\Rule;

use PhpstanContracts\Tests\Fixtures\FloatFixture;
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
                'castOfNanOrOutOfRange' => ['contracts.invalidCast: `(int) $x`'],

                'roundIsRejected' => ['contracts.unsupported: Call to round()'],
                'aFloatIsNeverIdenticalToAnInt' => ['contracts.unsupported: always false in PHP'],
                'minOfFloatsIsRejected' => ['contracts.unsupported: min() and max() are only supported on ints'],
                'moduloOfFloatsIsRejected' => ['contracts.unsupported: converts its operands to int'],
                'dividingTwoIntegersIsRejected' => ['contracts.unsupported: use intdiv()'],
            ],
        ]);
    }
}
