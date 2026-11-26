<?php

declare(strict_types=1);

namespace PhpstanContracts\Tests\Rule;

use PhpstanContracts\Tests\Fixtures\CallFixture;
use PhpstanContracts\Tests\Fixtures\Role;
use PhpstanContracts\Tests\Fixtures\ShadowParent;
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
                'callsALateStaticHelperOfAFinalClass' => [],
                'callsAMethodOnANestedObject' => [],
                'callsAFinalMethodOfAClassThatIsNotFinal' => [],

                'theHelperOverflowsForThisArgument' => ['contracts.overflow: `$x * 2` can leave the PHP int range, in the call to PhpstanContracts\Tests\Fixtures\CallFixture::double().'],
                'violatesAPrecondition' => ['contracts.requiresViolated: The call to PhpstanContracts\Tests\Fixtures\CallFixture::halve() can violate its precondition #[Requires(\'$x >= 0\')]. Counterexample: $x = -'],
                'anOverflowTwoCallsDeep' => [
                    'contracts.overflow: `$x * 2` can leave the PHP int range, in the call to PhpstanContracts\Tests\Fixtures\CallFixture::quadruple(), through PhpstanContracts\Tests\Fixtures\CallFixture::double().',
                    'contracts.overflow: `self::double($x) * 2` can leave the PHP int range, in the call to PhpstanContracts\Tests\Fixtures\CallFixture::quadruple().',
                ],
                'aPreconditionTwoCallsDeep' => ['contracts.requiresViolated: The call to PhpstanContracts\Tests\Fixtures\CallFixture::halve() can violate its precondition #[Requires(\'$x >= 0\')], in the call to PhpstanContracts\Tests\Fixtures\CallFixture::halveOf(). Counterexample: $x = -'],

                'recursion' => ['contracts.unsupported: Recursive calls are not supported: PhpstanContracts\Tests\Fixtures\CallFixture::recursion() is already being translated.'],
                // Point's coordinates are plain ints, so their sum can overflow.
                'callsAMethodInAnotherFile' => ['contracts.overflow: `$this->x + $this->y` can leave the PHP int range, in the call to PhpstanContracts\Tests\Fixtures\Point::sum(). Counterexample: $point = Point(x: '],
                'callsAMethodThatCanBeOverridden' => ['contracts.unsupported: PhpstanContracts\Tests\Fixtures\OpenShape::sides() can be overridden'],
                'callsAMethodThatIsNotVerifiable' => ['contracts.unsupported: The call to PhpstanContracts\Tests\Fixtures\CallFixture::loops() cannot be verified: Only foreach over a list is supported: a while, do or for loop needs a termination argument'],
                'callsWithATypeThatDoesNotFit' => ['contracts.unsupported: Argument $x of PhpstanContracts\Tests\Fixtures\CallFixture::double() is bool, but the parameter is int.'],
                'callInAContract' => ['contracts.unsupported: Method calls are not supported in contract expressions yet'],
                'aPhpDocFloatParameterWithoutANativeType' => ['contracts.unsupported: Parameter $value of PhpstanContracts\Tests\Fixtures\CallFixture::isOne() has no native type, so PHP passes its argument as it is; declare the type in the signature.'],
                'aNativeUnionParameter' => ['contracts.unsupported: Type float|int is not supported in verified methods'],
                'aNativeTypeThatDiffersFromThePhpDoc' => ['contracts.unsupported: Parameter $value of PhpstanContracts\Tests\Fixtures\CallFixture::isOneOfMixed() has the native type mixed, which is what PHP converts its argument to, but the PHPDoc type float; make them agree.'],
                'callsADeprecatedMethod' => ['contracts.unsupported: PhpstanContracts\Tests\Fixtures\CallFixture::deprecated() is #[\\Deprecated]: calling it triggers E_USER_DEPRECATED, which runs the error handler.'],

                'double' => [],
                'halve' => [],
                'withDefault' => [],
                'difference' => [],
                'half' => [],
                'magnitude' => [],
                'isRight' => [],
                'wrongContract' => ['contracts.ensuresViolated'],
                'loops' => [],
                'isOne' => [],
                'isOneOfUnion' => [],
                'isOneOfMixed' => [],
                'deprecated' => [],
                'quadruple' => [],
                'halveOf' => [],
            ],
        ]);
    }

    public function testAPrivateMethodIsTheOneOfTheCallingScopeExceptForStatic(): void
    {
        $this->assertOutcomes([
            ShadowParent::class => [
                'privateMethodOfTheCallingScope' => [],
                'privateMethodOfTheCallingScopeBroken' => ['contracts.ensuresViolated: => $result = 1'],
                'lateStaticCallOfAPrivateMethod' => ['contracts.unsupported: PhpstanContracts\Tests\Fixtures\ShadowParent::k() can be overridden: PhpstanContracts\Tests\Fixtures\ShadowParent is not final, so a subclass can declare its own k(), which static:: calls instead, even though this one is private. Use self::, or make the class or the method final.'],
                'm' => [],
                'k' => [],
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
