<?php

declare(strict_types=1);

namespace PhpstanContracts\Tests\Rule;

use PhpstanContracts\Tests\Fixtures\FactoryContractException;
use PhpstanContracts\Tests\Fixtures\FactoryOpenException;
use PhpstanContracts\Tests\Fixtures\FactorySelfException;
use PhpstanContracts\Tests\Fixtures\FactorySpecificException;
use PhpstanContracts\Tests\Fixtures\ThrowFactoryFixture;
use PhpstanContracts\Tests\Support\RuleFixtureTestCase;

final class ThrowFactoryRuleTest extends RuleFixtureTestCase
{
    public function testAnExceptionBuiltByAStaticFactory(): void
    {
        $this->assertOutcomes([
            ThrowFactoryFixture::class => [
                'fromWebId' => [],
                'fromWebIdBroken' => ['contracts.throwsViolated: #[Throws(PhpstanContracts\Tests\Fixtures\FactoryRangeException::class, when: \'$webId < 0 || $webId >= self::SIZE\')] is violated: the method can return although the condition holds. Counterexample: $webId = '],
                'namedArguments' => [],
                'lateStaticReturnType' => [],
                'anIntForAFloatParameter' => [],
                'anOptionalParameterLeftOut' => [],
                'inAMatchArm' => [],
                'inACoalesce' => [],
                'aSpecificDeclarationOnTheSameClass' => [],
                'aDeclarationOnAParentClass' => [],
                'aStaticReturnTypeResolvedToTheCalledClass' => [],
                'aNonFinalClassWithoutADeclarationOnItsSubclasses' => [],
                'aDeclarationOnlyOnASubclass' => ['contracts.unexpectedThrow: can be reached where no #[Throws] allows it. Counterexample: $n = -'],
                'aConditionOnASubclassOfTheDeclaredReturnType' => ['contracts.unsupported: PhpstanContracts\Tests\Fixtures\FactoryBaseException::make() returns PhpstanContracts\Tests\Fixtures\FactoryBaseException, which is not final: the object can be an instance of a subclass'],
                'anArgumentThatCanOverflow' => ['contracts.overflow'],
                'anArgumentThatDividesByZero' => ['contracts.divisionByZero'],
                'throwsOutsideTheCondition' => ['contracts.unexpectedThrow: can be reached where the #[Throws] declarations do not allow it: they allow it only where #[Throws(PhpstanContracts\Tests\Fixtures\FactoryRangeException::class, when: \'$n < 0\')] holds. Counterexample: $n = '],
                'notDeclaredForTheClass' => ['contracts.unexpectedThrow: can be reached where no #[Throws] allows it'],
                'aFloatForAnIntParameter' => ['contracts.unsupported: Argument $value of PhpstanContracts\Tests\Fixtures\FactoryRangeException::atLeast() is float, but the parameter is int.'],
                'aStringForAnIntParameter' => ['contracts.unsupported: Argument $value of PhpstanContracts\Tests\Fixtures\FactoryRangeException::atLeast() is string, but the parameter is int.'],
                'anIntForAStringParameter' => ['contracts.unsupported: Argument $text of PhpstanContracts\Tests\Fixtures\FactoryRangeException::ofString() is int, but the parameter is string.'],
                'aMissingArgument' => ['contracts.unsupported: Every parameter of PhpstanContracts\Tests\Fixtures\FactoryRangeException::atLeast() without a default value needs an argument.'],
                'anUnknownNamedArgument' => ['contracts.unsupported: Invalid arguments for PhpstanContracts\Tests\Fixtures\FactoryRangeException::atLeast().'],
                'aNonStaticFactory' => ['contracts.unsupported: PhpstanContracts\Tests\Fixtures\FactoryRangeException::instance() is not a static method'],
                'aPrivateFactory' => ['contracts.unsupported: PhpstanContracts\Tests\Fixtures\FactoryRangeException::hidden() is not accessible here.'],
                'aFactoryThatDoesNotExist' => ['contracts.unsupported: PhpstanContracts\Tests\Fixtures\FactoryRangeException has no method missing().'],
                'aVariable' => ['contracts.unsupported: Only `throw new Exception(...)` and `throw Exception::factory(...)` with a static factory are supported'],
                'aMethodOfAnObject' => ['contracts.unsupported: throw of the result of a method call is not supported'],
                'aDynamicClassName' => ['contracts.unsupported: The class and the method of an exception factory must be written by name'],
                'aDynamicMethodName' => ['contracts.unsupported: The class and the method of an exception factory must be written by name'],
                'aNewOfADynamicClass' => ['contracts.unsupported: The class of a thrown exception must be written by name'],
                'aFirstClassCallable' => ['contracts.unsupported: First-class callable syntax builds a Closure'],
                'aFunctionCall' => ['contracts.unsupported: Only `throw new Exception(...)` and `throw Exception::factory(...)` with a static factory are supported'],
                'aFactoryWithoutANativeReturnType' => ['contracts.unsupported: PhpstanContracts\Tests\Fixtures\FactoryRangeException::noType() returns no declared type, which is not a single exception class'],
                'aFactoryDocumentedAsReturningAnException' => ['contracts.unsupported: PhpstanContracts\Tests\Fixtures\FactoryRangeException::documentedOnly() returns no declared type'],
                'aFactoryReturningSomethingElse' => ['contracts.unsupported: PhpstanContracts\Tests\Fixtures\FactoryRangeException::notAnException() returns stdClass, which is not an exception class'],
                'aFactoryReturningAnInterface' => ['contracts.unsupported: PhpstanContracts\Tests\Fixtures\FactoryRangeException::asInterface() returns Throwable, which is not an exception class'],
                'aFactoryReturningAUnion' => ['contracts.unsupported: PhpstanContracts\Tests\Fixtures\FactoryRangeException::either() returns '],
                'aFactoryReturningNullable' => ['contracts.unsupported: PhpstanContracts\Tests\Fixtures\FactoryRangeException::nullable() returns '],
                'aFactoryThatNeverReturns' => ['contracts.unsupported: PhpstanContracts\Tests\Fixtures\FactoryRangeException::neverReturns() returns never'],
                'aFactoryWithAVariadicParameter' => ['contracts.unsupported: PhpstanContracts\Tests\Fixtures\FactoryRangeException::many() has a variadic or by-reference parameter'],
                'aFactoryWithAPrecondition' => ['contracts.unsupported: PhpstanContracts\Tests\Fixtures\FactoryContractException::guarded() carries #[Requires]'],
                'aFactoryThatDeclaresThrows' => ['contracts.unsupported: PhpstanContracts\Tests\Fixtures\FactoryContractException::thatThrows() carries #[Throws]'],
                'throwInsideAnOperation' => ['contracts.unsupported: throw is only supported as a statement'],
            ],
            FactorySelfException::class => [
                'guard' => [],
                'guardLateBound' => [],
                'guardWithAPrivateFactory' => [],
                'forId' => [],
                'hidden' => [],
            ],
            FactorySpecificException::class => [
                'aParentFactory' => ['contracts.unsupported: parent:: is not supported'],
                'specific' => [],
            ],
            FactoryContractException::class => [
                'guarded' => ['contracts.unsupported'],
                'thatThrows' => ['contracts.unsupported'],
            ],
            FactoryOpenException::class => [
                'guardLateBound' => ['contracts.unsupported: static:: is late bound'],
                'guard' => [],
                'forId' => [],
            ],
        ]);
    }
}
