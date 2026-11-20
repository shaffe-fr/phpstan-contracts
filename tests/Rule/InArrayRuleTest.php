<?php

declare(strict_types=1);

namespace PhpstanContracts\Tests\Rule;

use PhpstanContracts\Tests\Fixtures\InArrayFixture;
use PhpstanContracts\Tests\Fixtures\Shadowing\ShadowedInArray;
use PhpstanContracts\Tests\Support\RuleFixtureTestCase;

final class InArrayRuleTest extends RuleFixtureTestCase
{
    public function testInArrayOverAListLiteral(): void
    {
        $this->assertOutcomes([
            InArrayFixture::class => [
                'intsCompareAsWithEquals' => [],
                'stringsWithStrict' => [],
                'namedArguments' => [],
                'anIntEqualsAFloatWithoutStrict' => [],
                'elementsCanBeExpressions' => [],
                'anEmptyListContainsNothing' => [],
                'nullWithStrict' => [],
                'inAContract' => [],
                'inArrayInAnEnsures' => [],

                'strictCatchesAMissingCode' => ['contracts.ensuresViolated: Counterexample: $code = \'USD\' => $result = true'],

                'stringsWithoutStrict' => ['contracts.unsupported: in_array() without strict: true compares with ==, which juggles a string; pass true as the third argument.'],
                'nullableWithoutStrict' => ['contracts.unsupported: in_array() without strict: true compares with ==, which juggles a ?int'],
                'strictComparisonOfDifferentTypes' => ['contracts.unsupported: int === float is always false in PHP'],
                'keyedHaystack' => ['contracts.unsupported: in_array() is only supported on a list literal without keys'],
                'strictFlagThatIsNotALiteral' => ['contracts.unsupported: The strict argument of in_array() must be the literal true or false.'],
                'aBoolNeedleWithoutStrict' => [],
                'aFloatNeedleWithoutStrict' => [],
                'aBoolNeedleAgainstIntsWithoutStrict' => ['contracts.unsupported: Cannot compare bool with int.'],
                'aHaystackThatIsAVariable' => ['contracts.unsupported: in_array() is only supported on a list literal, such as [self::A, self::B], or a class constant holding one.'],
                'aConstantThatIsNotAList' => ['contracts.unsupported: Constant PhpstanContracts\Tests\Fixtures\InArrayFixture::NOT_A_LIST does not hold a single array literal.'],
                'aMissingHaystack' => ['contracts.unsupported: in_array() takes a needle, a haystack and an optional strict flag.'],
                'anUnknownNamedArgument' => ['contracts.unsupported: in_array() takes a needle, a haystack and an optional strict flag.'],
            ],
        ]);
    }

    public function testAnInArrayOfTheNamespaceIsNotTheBuiltIn(): void
    {
        $this->assertOutcomes([
            ShadowedInArray::class => [
                'callsTheFunctionOfTheNamespace' => ['contracts.unsupported: Call to PhpstanContracts\Tests\Fixtures\Shadowing\in_array() is not part of the verified subset: purity cannot be established.'],
                'callsTheBuiltInFunction' => [],
            ],
        ]);
    }
}
