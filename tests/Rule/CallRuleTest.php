<?php

declare(strict_types=1);

namespace PhpstanContracts\Tests\Rule;

use PhpstanContracts\Tests\Fixtures\CallFixture;
use PhpstanContracts\Tests\Fixtures\Role;
use PhpstanContracts\Tests\Support\RuleFixtureTestCase;

final class CallRuleTest extends RuleFixtureTestCase
{
    public function testCallsToMethodsOfTheSameFileAreTranslatedWithTheirBody(): void
    {
        $this->assertOutcomes([
            CallFixture::class => [
                'callsAStaticHelper' => [],
                'callsAHelperByTheClassName' => [],
                'satisfiesAPrecondition' => [],
                'usesADefaultValue' => [],
                'namedArguments' => [],
                'anIntArgumentForAFloatParameter' => [],
                'aHelperWithSeveralReturns' => [],
                'callsAHelperWithAnObject' => [],
                'contractOfTheCalleeIsNotNeeded' => [],

                'theHelperOverflowsForThisArgument' => ['contracts.overflow: `$x * 2` can leave the PHP int range, in the call to PhpstanContracts\Tests\Fixtures\CallFixture::double().'],
                'violatesAPrecondition' => ['contracts.requiresViolated: The call to PhpstanContracts\Tests\Fixtures\CallFixture::halve() can violate its precondition #[Requires(\'$x >= 0\')]. Counterexample: $x = -'],

                'recursion' => ['contracts.unsupported: Recursive calls are not supported: PhpstanContracts\Tests\Fixtures\CallFixture::recursion() is already being translated.'],
                'callsAMethodInAnotherFile' => ['contracts.unsupported: PhpstanContracts\Tests\Fixtures\Point::sum() is declared in another file.'],
                'callsAMethodThatCanBeOverridden' => ['contracts.unsupported: PhpstanContracts\Tests\Fixtures\OpenShape::sides() can be overridden'],
                'callsAMethodThatIsNotVerifiable' => ['contracts.unsupported: The call to PhpstanContracts\Tests\Fixtures\CallFixture::loops() cannot be verified: Loops are not supported'],
                'callsWithATypeThatDoesNotFit' => ['contracts.unsupported: Argument $x of PhpstanContracts\Tests\Fixtures\CallFixture::double() is bool, but the parameter is int.'],
                'callInAContract' => ['contracts.unsupported: Method calls are not supported in contract expressions yet'],

                'double' => [],
                'halve' => [],
                'withDefault' => [],
                'difference' => [],
                'half' => [],
                'magnitude' => [],
                'isRight' => [],
                'wrongContract' => ['contracts.ensuresViolated'],
                'loops' => [],
            ],
        ]);
    }

    public function testEnumMethodsCallEachOther(): void
    {
        $this->assertOutcomes([
            Role::class => [
                'hierarchyLevel' => [],
                'canManage' => [],
                'canValidate' => [],
                'canValidateFromAConstant' => [],

                'canManageBroken' => ['contracts.ensuresViolated: Counterexample: $this = Role::'],
                'canPrescribeBroken' => ['contracts.ensuresViolated: Counterexample: $this = Role::Validator => $result = false'],

                'callsAStaticMethodOnAnInstance' => ['contracts.unsupported: PhpstanContracts\Tests\Fixtures\Role::canManage() is an instance method; call it on the object'],
            ],
        ]);
    }
}
