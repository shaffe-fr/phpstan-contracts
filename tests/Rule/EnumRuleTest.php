<?php

declare(strict_types=1);

namespace PhpstanContracts\Tests\Rule;

use PhpstanContracts\Tests\Fixtures\EnumFixture;
use PhpstanContracts\Tests\Support\RuleFixtureTestCase;

final class EnumRuleTest extends RuleFixtureTestCase
{
    public function testEnumRules(): void
    {
        $this->assertOutcomes([
            EnumFixture::class => [
                'exhaustiveMatchOnAPureEnum' => [],
                'enumIdentity' => [],
                'matchAgreesWithTheIntBackingValue' => [],
                'stringBackingValue' => [],
                'caseName' => [],
                'fromTheValueOfACaseGivesTheCase' => [],
                'fromAnIntegerLiteral' => [],
                'tryFromAnUnknownValueIsNull' => [],
                'tryFromWithADefault' => [],
                'fullyQualifiedNamesAreResolvedInContracts' => [],

                'matchWithAMissingCase' => ['contracts.unhandledMatch: Counterexample: $suit = Suit::Clubs'],
                'fromAnUntrustedString' => ['contracts.invalidEnumValue: `\PhpstanContracts\Tests\Fixtures\Code::from($raw)` can throw ValueError'],
                'fromAnUnboundedInteger' => ['contracts.invalidEnumValue: `\PhpstanContracts\Tests\Fixtures\Level::from($n)`'],

                'valueOfAPureEnumDoesNotExist' => ['contracts.unsupported: not a backed enum'],
                'casesIsNotSupported' => ['contracts.unsupported: Call to count()'],
                'tryFromNeedsTheBackingType' => ['contracts.unsupported: Code::tryFrom() expects a string'],
            ],
        ]);
    }
}
