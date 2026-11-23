<?php

declare(strict_types=1);

namespace PhpstanContracts\Tests\Rule;

use PhpstanContracts\Tests\Fixtures\EnumFixture;
use PhpstanContracts\Tests\Fixtures\EnumFromFixture;
use PhpstanContracts\Tests\Fixtures\Stock;
use PhpstanContracts\Tests\Fixtures\SymbolNames;
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
                'casesIsNotSupported' => ['contracts.unsupported: PhpstanContracts\Tests\Fixtures\Suit::cases() is declared in another file'],
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

    /**
     * `Order\Status` and `Order_Status`, or `A::B_C` and `A_B::C`, are different names and must stay apart in the solver.
     */
    public function testDifferentNamesGiveDifferentSymbols(): void
    {
        $this->assertOutcomes([
            SymbolNames::class => [
                'sumOfTwoEnumsWithCloseNames' => ['contracts.ensuresViolated: Counterexample: $modern = Status::'],
                'casesWhoseNamesJoinTheSameWay' => ['contracts.ensuresViolated: Counterexample: $a = A::'],
            ],
        ]);
    }

    public function testFromIsBuiltInOnlyForBackedEnums(): void
    {
        $this->assertOutcomes([
            EnumFromFixture::class => [
                'callsTheFromOfAPureEnum' => [],
                'fromOfAPureEnumWithoutOne' => ['contracts.unsupported: Flag is not a backed enum, so it has no from().'],
                'tryFromOfAnEnumWithAnUnfoldedValue' => ['contracts.unsupported: Limit::tryFrom() is not supported: The backing value of PhpstanContracts\Tests\Fixtures\Limit::Huge cannot be folded to a literal; write it as a literal.'],
                'valueOfAnEnumWithAnUnfoldedValue' => ['contracts.unsupported: ->value of Limit is not supported: The backing value of PhpstanContracts\Tests\Fixtures\Limit::Huge cannot be folded to a literal'],
                'caseOfAnEnumWithAnUnfoldedValue' => [],
            ],
        ]);
    }
}
