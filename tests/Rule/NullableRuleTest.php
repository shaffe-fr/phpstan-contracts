<?php

declare(strict_types=1);

namespace PhpstanContracts\Tests\Rule;

use PhpstanContracts\Tests\Fixtures\LiteralNullables;
use PhpstanContracts\Tests\Fixtures\MatchNarrowing;
use PhpstanContracts\Tests\Fixtures\NullableFixture;
use PhpstanContracts\Tests\Support\RuleFixtureTestCase;

final class NullableRuleTest extends RuleFixtureTestCase
{
    public function testNullableRules(): void
    {
        $this->assertOutcomes([
            NullableFixture::class => [
                'coalesce' => [],
                'earlyReturnNarrows' => [],
                'ifBranchNarrows' => [],
                'elseBranchNarrows' => [],
                'ternaryNarrows' => [],
                'issetNarrows' => [],
                'isNullNarrows' => [],
                'matchTrueNarrows' => [],
                'disjunctionNarrowsItsRightSide' => [],
                'conjunctionNarrowsItsRightSide' => [],
                'assignmentNarrows' => [],
                'coalesceAssignment' => [],
                'nullableResult' => [],
                'nullPropagates' => [],
                'nullableBooleans' => [],
                'nullableIdentity' => [],
                'clampedOptionalLimit' => [],

                'forgottenClamp' => ['contracts.ensuresViolated: Counterexample: $limit = '],

                'narrowingIsForgottenAfterAReassignment' => ['contracts.unsupported: which may be null here'],
                'arithmeticOnANullableValue' => ['contracts.unsupported: Expected a number (int or float) but got ?int, which may be null here'],
                'looseComparisonWithNull' => ['contracts.unsupported: Comparing with null using =='],
                'nullWithoutAType' => ['contracts.unsupported: Cannot infer a type for $x from null'],
                'anIntegerVariableCannotBecomeNull' => ['contracts.unsupported: changes type from int to null'],
            ],
        ]);
    }

    /**
     * An arm with several conditions runs when any of them holds, so none of them narrows its body.
     */
    public function testAMatchArmWithSeveralConditionsIsNotNarrowedByEach(): void
    {
        $this->assertOutcomes([
            MatchNarrowing::class => [
                'eitherNotNull' => ['contracts.ensuresViolated: Counterexample: $a = null'],
                'divisionBehindTheOtherCondition' => ['contracts.divisionByZero: Possible division by zero in `intdiv(100, $c)`.'],
                'aSingleConditionNarrows' => [],
            ],
        ]);
    }

    public function testANullableBuiltFromLiteralsIsDeclared(): void
    {
        $this->assertOutcomes([
            LiteralNullables::class => [
                'comparesALiteralNullableWithNull' => [],
                'isNullOfALiteralNullable' => [],
                'aClaimThatFails' => ['contracts.ensuresViolated: Counterexample: $b = false => $result = 2 (return at line 33).'],
            ],
        ]);
    }
}
