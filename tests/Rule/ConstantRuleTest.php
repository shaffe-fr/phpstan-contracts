<?php

declare(strict_types=1);

namespace PhpstanContracts\Tests\Rule;

use PhpstanContracts\Tests\Fixtures\AnnotatedFinal;
use PhpstanContracts\Tests\Fixtures\ConstantFixture;
use PhpstanContracts\Tests\Fixtures\ConstantInitializers;
use PhpstanContracts\Tests\Fixtures\ConstantOutsider;
use PhpstanContracts\Tests\Fixtures\GlobalConstantInitializers;
use PhpstanContracts\Tests\Fixtures\LateBinding;
use PhpstanContracts\Tests\Support\RuleFixtureTestCase;

final class ConstantRuleTest extends RuleFixtureTestCase
{
    /**
     * A bootstrap file of PHPStan defines a constant that only exists at runtime.
     *
     * @return list<string>
     */
    public static function getAdditionalConfigFiles(): array
    {
        return [...parent::getAdditionalConfigFiles(), __DIR__ . '/../config/runtime-constants.neon'];
    }

    public function testClassConstants(): void
    {
        $this->assertOutcomes([
            ConstantFixture::class => [
                'tryFrom' => [],
                'constantsInContracts' => [],
                'foldedConstantExpression' => [],
                'floatConstant' => [],
                'floatConstantWrittenAsAnInt' => [],
                'stringConstant' => [],
                'boolConstant' => [],
                'constantHoldingAnEnumCase' => [],
                'staticOnAFinalClass' => [],
                'inheritedConstant' => [],
                'constantOfAnotherClass' => [],
                'ownPrivateConstant' => [],
                'ownProtectedConstant' => [],
                'nullableFloatConstantWrittenAsAnInt' => [],
                'unionFloatConstantWrittenAsAnInt' => [],

                'tryFromBroken' => ['contracts.ensuresViolated: Counterexample: $webId = 1000000 => $result = 99000000'],

                'arrayConstant' => ['contracts.unsupported: Constant PhpstanContracts\Tests\Fixtures\ConstantFixture::CODES has type array'],
                'parentConstant' => ['contracts.unsupported: parent:: is not supported'],
                'classNameConstant' => ['contracts.unsupported: (::class)'],
                'privateConstantOfAnotherClass' => ['contracts.unsupported: ConstantBase::HIDDEN is not accessible here'],
                'missingConstant' => ['contracts.unsupported: has no constant MISSING'],
            ],
        ]);
    }

    public function testLateStaticBinding(): void
    {
        $this->assertOutcomes([
            LateBinding::class => [
                'selfIsStaticallyBound' => [],
                'finalConstantThroughStatic' => [],
                'classNameIsStaticallyBound' => [],

                'overridableConstantThroughStatic' => ['contracts.unsupported: static::LIMIT is late bound: a subclass of PhpstanContracts\Tests\Fixtures\LateBinding can override the constant'],
                'lateBindingInAContract' => ['contracts.unsupported: static::LIMIT is late bound'],
                'lateBoundCall' => ['contracts.unsupported: PhpstanContracts\Tests\Fixtures\LateBinding::from() can be overridden: PhpstanContracts\Tests\Fixtures\LateBinding is not final'],
                'from' => [],
            ],
            AnnotatedFinal::class => [
                'limit' => ['contracts.unsupported: static::LIMIT is late bound'],
            ],
            ConstantOutsider::class => [
                'protectedConstantOfAnotherClass' => ['contracts.unsupported: ConstantBase::GUARDED is not accessible here'],
            ],
        ]);
    }

    /**
     * A folded value is wrong when reading the constant triggers a deprecation, or when its
     * initializer turns a float into a string, which depends on the precision setting.
     */
    public function testConstantsWhoseFoldedValueCannotBeTrusted(): void
    {
        $this->assertOutcomes([
            ConstantInitializers::class => [
                'readsADeprecatedConstant' => ['contracts.unsupported: Constant PhpstanContracts\Tests\Fixtures\DeprecatedLimits::LIMIT is deprecated: reading it triggers a deprecation notice, which runs the error handler.'],
                'readsAConstantBuiltFromADeprecatedOne' => ['contracts.unsupported: Constant PhpstanContracts\Tests\Fixtures\DeprecatedLimits::LIMIT is deprecated'],
                'readsAConstant' => [],
                'readsADeprecatedCase' => ['contracts.unsupported: Enum case PhpstanContracts\Tests\Fixtures\Mode::Legacy is deprecated'],
                'matchesADeprecatedCase' => ['contracts.unsupported: Enum case PhpstanContracts\Tests\Fixtures\Mode::Legacy is deprecated'],
                'readsACase' => [],
                'concatenatesAFloatInAConstant' => ['contracts.unsupported: An initializer in PhpstanContracts\Tests\Fixtures\ConstantInitializers converts a float to a string, whose digits depend on the precision ini setting, which is not modelled.'],
                'concatenatesADivisionInAConstant' => ['contracts.unsupported: An initializer in PhpstanContracts\Tests\Fixtures\ConstantInitializers converts a float to a string'],
                'searchesAListWithAFloatLabel' => ['contracts.unsupported: An initializer in PhpstanContracts\Tests\Fixtures\ConstantInitializers converts a float to a string'],
                'concatenatesAnIntInAConstant' => [],
                'readsTheValueOfAnEnumWithAFloatLabel' => ['contracts.unsupported: ->value of FloatLabel is not supported: An initializer in PhpstanContracts\Tests\Fixtures\FloatLabel converts a float to a string'],
            ],
            GlobalConstantInitializers::class => [
                'readsAGlobalConstantThatPrintsAFloat' => ['contracts.unsupported: The initializer of constant PhpstanContracts\Tests\Fixtures\PRINTED converts a float to a string, whose digits depend on the precision ini setting, which is not modelled.'],
                'concatenatesAGlobalConstantThatPrintsAFloat' => ['contracts.unsupported: The initializer of constant PhpstanContracts\Tests\Fixtures\PRINTED converts a float to a string'],
                'readsABoolThatComparesAPrintedFloat' => ['contracts.unsupported: The initializer of constant PhpstanContracts\Tests\Fixtures\PRINTS_SHORT converts a float to a string'],
                'readsAConstantDefinedByDefine' => ['contracts.unsupported: The initializer of constant PRINTED_BY_DEFINE converts a float to a string'],
                'readsTheValueOfAnEnumCaseSetByAGlobalConstant' => ['contracts.unsupported: ->value of GlobalLabel is not supported: The initializer of constant PhpstanContracts\Tests\Fixtures\PRINTED converts a float to a string'],
                'readsAGlobalIntConstant' => [],
                'readsAGlobalConstantThatPrintsAnInt' => [],
                'readsAConstantOnlyKnownAtRuntime' => ['contracts.unsupported: The initializer of constant PhpstanContracts\Tests\Fixtures\DEFINED_AT_RUNTIME cannot be checked for a float converted to a string: no file defines it.'],
            ],
        ]);
    }
}
