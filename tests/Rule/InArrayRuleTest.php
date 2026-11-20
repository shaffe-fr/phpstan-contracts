<?php

declare(strict_types=1);

namespace PhpstanContracts\Tests\Rule;

use PhpstanContracts\Tests\Fixtures\InArrayFixture;
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
            ],
        ]);
    }
}
