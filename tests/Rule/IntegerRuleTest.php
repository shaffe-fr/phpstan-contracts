<?php

declare(strict_types=1);

namespace PhpstanContracts\Tests\Rule;

use PhpstanContracts\Tests\Fixtures\IntegerFixture;
use PhpstanContracts\Tests\Support\RuleFixtureTestCase;

final class IntegerRuleTest extends RuleFixtureTestCase
{
    public function testIntegerRules(): void
    {
        $this->assertOutcomes([
            IntegerFixture::class => [
                // Proven.
                'sum' => [],
                'earlyReturn' => [],
                'elseIfChain' => [],
                'entryValuesSurviveReassignment' => [],
                'ternary' => [],
                'matchWithDefault' => [],
                'matchTrue' => [],
                'divisionAndModuloObeyTheLawsOfTruncatedDivision' => [],
                'maximumOfTwo' => [],
                'minimumOfTwo' => [],
                'absoluteValue' => [],
                'divisionGuardedByAnd' => [],
                'divisionGuardedByTernary' => [],
                'implication' => [],
                'pureOnlyIsProvenSafe' => [],
                'requiresRelatesParameters' => [],
                'returnRangeIsRespected' => [],

                // A contract that can be violated.
                'postconditionViolated' => ['contracts.ensuresViolated: Postcondition #[Ensures(\'$result > 50\')] can be violated'],
                'onlyTheFailingClauseIsReported' => ['contracts.ensuresViolated: $result > 100'],
                'returnRangeIsViolated' => ['contracts.returnType: `int<0, 10>`'],

                // The automatic safety checks.
                'additionOverflows' => ['contracts.overflow: `$a + $b`'],
                'multiplicationOverflows' => ['contracts.overflow: `$a * $b`'],
                'negationOverflows' => ['contracts.overflow: `-$a`'],
                'absoluteValueOverflows' => ['contracts.overflow: `abs($a)`'],
                'divisionByZero' => ['contracts.divisionByZero: `intdiv($a, $b)`'],
                'moduloByZero' => ['contracts.divisionByZero: `$a % $b`'],
                'minimumDividedByMinusOneOverflows' => ['contracts.divisionByZero', 'contracts.overflow: `intdiv($a, $b)`'],
                'guardOfTheWrongOperand' => ['contracts.divisionByZero'],
                'matchDoesNotCoverEveryValue' => ['contracts.unhandledMatch'],
                'pathWithoutReturn' => ['contracts.missingReturn: Not every code path returns a value'],
                'contradictoryPreconditions' => ['contracts.contradictoryRequires'],
            ],
        ]);
    }
}
