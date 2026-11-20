<?php

declare(strict_types=1);

namespace PhpstanContracts\Tests\Rule;

use PhpstanContracts\Tests\Fixtures\StringFixture;
use PhpstanContracts\Tests\Support\RuleFixtureTestCase;

final class StringRuleTest extends RuleFixtureTestCase
{
    public function testStringRules(): void
    {
        $this->assertOutcomes([
            StringFixture::class => [
                'concatenation' => [],
                'aPrefixIsNotLongerThanTheText' => [],
                'everyStringContainsTheEmptyString' => [],
                'theEuroSignIsThreeBytes' => [],
                'literalsKeepQuotesBackslashesAndNulBytes' => [],
                'nonAsciiLiteralsAreTheirUtf8Bytes' => [],
                'nonEmptyStringHasPositiveLength' => [],
                'identicalStringsHaveTheSameLength' => [],
                'compoundConcatenation' => [],
                'literalsConcatenate' => [],
                'interpolationOfAString' => [],
                'concatenatingAnInteger' => [],

                'containsIsNotEndsWith' => ['contracts.ensuresViolated: Counterexample: $file = '],
                'oneCharacterIsNotOneByte' => ['contracts.ensuresViolated: $result = "\xE2\x82\xAC"'],

                'looseEqualityOfStringsIsRejected' => ['contracts.unsupported: Strings compared with =='],
                'substrIsNotSupported' => ['contracts.unsupported: Call to substr()'],
                'orderingStringsIsRejected' => ['contracts.unsupported: Expected a number'],
            ],
        ]);
    }
}
