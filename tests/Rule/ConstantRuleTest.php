<?php

declare(strict_types=1);

namespace PhpstanContracts\Tests\Rule;

use PhpstanContracts\Tests\Fixtures\AnnotatedFinal;
use PhpstanContracts\Tests\Fixtures\ConstantFixture;
use PhpstanContracts\Tests\Fixtures\ConstantOutsider;
use PhpstanContracts\Tests\Fixtures\LateBinding;
use PhpstanContracts\Tests\Support\RuleFixtureTestCase;

final class ConstantRuleTest extends RuleFixtureTestCase
{
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
}
