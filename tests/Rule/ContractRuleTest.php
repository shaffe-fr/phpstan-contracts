<?php

declare(strict_types=1);

namespace PhpstanContracts\Tests\Rule;

use PhpstanContracts\Tests\Fixtures\ContractFixture;
use PhpstanContracts\Tests\Fixtures\Counter;
use PhpstanContracts\Tests\Fixtures\NegativeCounter;
use PhpstanContracts\Tests\Fixtures\Shape;
use PhpstanContracts\Tests\Support\RuleFixtureTestCase;

final class ContractRuleTest extends RuleFixtureTestCase
{
    public function testContractRules(): void
    {
        $this->assertOutcomes([
            ContractFixture::class => [
                'anUnannotatedMethodIsNeverChecked' => [],
                'shortNamesResolveAgainstTheSignature' => [],
                'instanceMethodsAreFineWithoutThis' => [],

                'syntaxErrorInAContract' => ['contracts.invalidContract: is not valid PHP'],
                'aContractMustBeALiteral' => ['contracts.invalidContract: plain string literal'],
                'aContractIsOneExpression' => ['contracts.invalidContract: exactly one PHP expression'],
                'unresolvableClassInAContract' => ['contracts.invalidContract: Cannot resolve class Missing'],
                'parameterNamedResult' => ['contracts.invalidContract: reserved for #[Ensures]'],

                'unknownVariableInAContract' => ['contracts.unsupported: Variable $nope is not defined'],
                'resultIsNotAvailableInAPrecondition' => ['contracts.unsupported: Variable $result is not defined'],
                'aContractMustBeBoolean' => ['contracts.unsupported: Expected a bool expression'],
                'unknownEnumCaseInAContract' => ['contracts.unsupported: Enum Suit has no case Diamonds'],
                'impliesOnlyExistsInContracts' => ['contracts.unsupported: only exists inside contract expressions'],
                'instanceMethodReadingThis' => ['contracts.unsupported: Object state ($this)'],
            ],
        ]);
    }

    /**
     * Nothing checks a contract on a method without a body, and implementations do not inherit it.
     */
    public function testAContractOnAMethodWithoutABodyIsRejected(): void
    {
        $this->assertOutcomes([
            Counter::class => [
                'count' => ['contracts.unsupported: Contracts on abstract and interface methods are not verified, and the methods that implement them do not inherit them: put the contract on each concrete method.'],
                'reset' => [],
            ],
            Shape::class => [
                'sides' => ['contracts.unsupported: Contracts on abstract and interface methods are not verified'],
                'corners' => [],
            ],
            NegativeCounter::class => [
                'count' => [],
                'reset' => [],
            ],
        ]);
    }
}
