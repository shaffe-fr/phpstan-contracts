<?php

declare(strict_types=1);

namespace PhpstanContracts\Tests\Rule;

use PhpstanContracts\Tests\Fixtures\IntToStringFixture;
use PhpstanContracts\Tests\Support\RuleFixtureTestCase;

final class IntToStringRuleTest extends RuleFixtureTestCase
{
    public function testAnIntIsConvertedToItsDecimalString(): void
    {
        $this->assertOutcomes([
            IntToStringFixture::class => [
                'inStockDelay' => [],
                'aNegativeInt' => [],
                'theSmallestInt' => [],
                'digits' => [],
                'noLeadingZero' => [],
                'interpolationWithBraces' => [],
                'interpolatedProperties' => [],
                'concatAssign' => [],
                'aStringCastIsTheString' => [],

                'inStockDelayBroken' => ['contracts.ensuresViolated: Counterexample: $days = 1 => $result = \'1 jours\''],
                'digitsBroken' => ['contracts.ensuresViolated: Counterexample: $n = '],

                'aFloatInAConcatenation' => ['contracts.unsupported: Converting a float to a string is not supported: only strings and ints can be concatenated or interpolated.'],
                'aBoolInAConcatenation' => ['contracts.unsupported: Converting a bool to a string is not supported'],
                'aNullableIntWithoutACheck' => ['contracts.unsupported: Converting a ?int to a string is not supported'],
                'aFloatCastToString' => ['contracts.unsupported: Converting a float to a string is not supported'],
                'aBoolCast' => ['contracts.unsupported: Only (int), (float) and (string) casts are supported.'],
            ],
        ]);
    }
}
