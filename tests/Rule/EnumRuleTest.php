<?php

declare(strict_types=1);

namespace PhpstanContracts\Tests\Rule;

use PhpstanContracts\Tests\Fixtures\EnumFixture;
use PhpstanContracts\Tests\Fixtures\Stock;
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

    public function testSelfAndThisInsideAnEnum(): void
    {
        $this->assertOutcomes([
            Stock::class => [
                'fromQuantity' => [],
                'staticIsTheEnumItself' => [],
                'tryFromThroughSelf' => [],
                'constantHoldingACase' => [],
                'isPurchasable' => [],
                'identityOfThis' => [],
                'valueOfThis' => [],
                'nameOfThis' => [],
                'thisInAPreconditionOnly' => [],

                'isPurchasableBroken' => ['contracts.ensuresViolated: Counterexample: $this = Stock::TemporarilyUnavailable => $result = true'],
                'partialMatchOnThis' => ['contracts.unhandledMatch: Counterexample: $this = Stock::TemporarilyUnavailable.'],

                'methodCallOnThis' => [],
                'noThisInAStaticMethod' => ['contracts.unsupported: There is no $this in a static method.'],
            ],
        ]);
    }
}
