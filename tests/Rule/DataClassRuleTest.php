<?php

declare(strict_types=1);

namespace PhpstanContracts\Tests\Rule;

use PhpstanContracts\Tests\Fixtures\ClassNameCase;
use PhpstanContracts\Tests\Fixtures\Construction;
use PhpstanContracts\Tests\Fixtures\DataClassFixture;
use PhpstanContracts\Tests\Fixtures\Initialization;
use PhpstanContracts\Tests\Fixtures\Interval;
use PhpstanContracts\Tests\Fixtures\PriceContext;
use PhpstanContracts\Tests\Fixtures\PropertyShadowParent;
use PhpstanContracts\Tests\Fixtures\PropertyShadowReader;
use PhpstanContracts\Tests\Fixtures\Sealed;
use PhpstanContracts\Tests\Support\RuleFixtureTestCase;

final class DataClassRuleTest extends RuleFixtureTestCase
{
    public function testDataClassRules(): void
    {
        $this->assertOutcomes([
            DataClassFixture::class => [
                'readsAField' => [],
                'sumOfBoundedFields' => [],
                'nestedRead' => [],
                'buildsAnObject' => [],
                'namedArgumentsFollowTheirNames' => [],
                'swapsTheCoordinates' => [],
                'buildsAClassOfAnotherFile' => ['contracts.unsupported: Cannot instantiate Point: its constructor is declared in another file. Only classes whose constructor is in the same file can be built: PHPStan re-analyses a file when the signatures it uses change, not when a body in another file does, so the proof would not be redone.'],
                'nullsafeRead' => [],
                'nullCheckOnAField' => [],
                'narrowedByANullCheck' => [],
                'aFieldRangeIsAnAssumptionOnInputs' => [],
                'aReturnedObjectStaysInRange' => [],
                'readsAStringField' => [],
                'buildsADataClassWithAString' => [],

                'aReturnedObjectOutOfRange' => ['contracts.returnType: `PhpstanContracts\Tests\Fixtures\Percent`'],
                'postconditionOnAField' => ['contracts.ensuresViolated: $result->x > 0'],

                'constructorWithLogic' => ['contracts.unsupported: Cannot instantiate Checked: its constructor contains logic'],
                'mutableClass' => ['contracts.unsupported: must be public and readonly'],
                'privateProperty' => ['contracts.unsupported: must be public and readonly'],
                'recursiveClass' => ['contracts.unsupported: refers to itself'],
                'classWithoutProperties' => ['contracts.unsupported: has no properties to model'],
                'constructorThatDoesNotPromote' => ['contracts.unsupported: its constructor takes parameters that are not promoted properties'],
                'identityOfObjects' => ['contracts.unsupported: compared by identity'],
                'methodCallOnAnObject' => ['contracts.unsupported: PhpstanContracts\Tests\Fixtures\Point::sum() is declared in another file'],
                'nullableObjectWithoutACheck' => ['contracts.unsupported: may be null here'],
                'everyConstructorArgumentIsRequired' => ['contracts.unsupported: Every constructor argument of Position must be passed explicitly'],

                'inheritedStateThatIsNeverRead' => [],
                'readsTheModelledPartOfAnObject' => [],
                'readsAMutableProperty' => ['contracts.unsupported: Property PhpstanContracts\Tests\Fixtures\PartlyMutable::$count must be public and readonly'],
                'readsAPropertyOfAnUnsupportedType' => ['contracts.unsupported: Property PhpstanContracts\Tests\Fixtures\PartlyMutable::$tags cannot be read in a verified method: Type list<string> is not supported'],
                'buildsAnObjectWithAnUnmodelledProperty' => ['contracts.unsupported: Cannot instantiate PartlyMutable: its constructor sets $tags, which is not modelled'],
                'nullsafeReadOfAMutableProperty' => ['contracts.unsupported: Property PhpstanContracts\Tests\Fixtures\PartlyMutable::$count must be public and readonly'],
                'issetOfAMutableProperty' => ['contracts.unsupported: Property PhpstanContracts\Tests\Fixtures\PartlyMutable::$count must be public and readonly'],
                'onlyAPropertyOfAnUnsupportedType' => ['contracts.unsupported: Property PhpstanContracts\Tests\Fixtures\OnlyTags::$tags cannot be read in a verified method: Type list<string> is not supported'],
                'holdsARecursiveClass' => ['contracts.unsupported: Class PhpstanContracts\Tests\Fixtures\Recursive refers to itself; recursive types are not supported.'],
            ],
            PriceContext::class => [
                'isBulk' => [],
                'buildsAnObjectWithInheritedState' => [],
                'readsInheritedState' => ['contracts.unsupported: Property PhpstanContracts\Tests\Fixtures\PriceContext::$_additional must be public and readonly'],
            ],
        ]);
    }

    public function testAClassWrittenInAnotherCaseIsTheSameClass(): void
    {
        $this->assertOutcomes([
            ClassNameCase::class => [
                'sameClassInTwoCases' => [],
                'buildsTheClassInAnotherCase' => [],
                'sameClassInTwoCasesBroken' => ['contracts.ensuresViolated: Counterexample: $a = Coordinate(x: '],
            ],
        ]);
    }

    /**
     * From a class that declares a private $x, `$c->x` on an instance of a subclass reads
     * that private property, not the public readonly $x of the subclass.
     */
    public function testAPrivatePropertyOfTheCallingClassShadowsTheSubclassProperty(): void
    {
        $this->assertOutcomes([
            PropertyShadowParent::class => [
                'readsThePrivatePropertyOfTheCallingClass' => ['contracts.unsupported: From PhpstanContracts\Tests\Fixtures\PropertyShadowParent, $c->x reads the private property PhpstanContracts\Tests\Fixtures\PropertyShadowParent::$x, not PhpstanContracts\Tests\Fixtures\PropertyShadowChild::$x: PHP picks the private property of the calling class. Rename one of the two properties.'],
                'nullsafeReadOfThePrivatePropertyOfTheCallingClass' => ['contracts.unsupported: From PhpstanContracts\Tests\Fixtures\PropertyShadowParent, $c?->x reads the private property PhpstanContracts\Tests\Fixtures\PropertyShadowParent::$x, not PhpstanContracts\Tests\Fixtures\PropertyShadowChild::$x'],
                'readsAPropertyThatThisClassDoesNotDeclare' => [],
            ],
            PropertyShadowReader::class => [
                'readsThePropertyOfTheChild' => [],
            ],
        ]);
    }

    public function testThisInADataClass(): void
    {
        $this->assertOutcomes([
            Interval::class => [
                'length' => [],
                'contains' => [],
                'stepOrOne' => [],
                'withEnd' => [],

                'lengthBroken' => ['contracts.ensuresViolated: Counterexample: $this = Interval(start: '],

                'methodCallOnThis' => ['contracts.requiresViolated: The call to PhpstanContracts\Tests\Fixtures\Interval::length() can violate its precondition #[Requires(\'$this->start <= $this->end\')]. Counterexample: $this = Interval('],
            ],
        ]);
    }

    /**
     * A property is only modelled when every object of the type has it set: reading one that is not throws an Error.
     */
    public function testOnlyPropertiesThatAreAlwaysSetAreModelled(): void
    {
        $this->assertOutcomes([
            Initialization::class => [
                'readsAPropertyOfANonFinalClass' => ['contracts.unsupported: Property PhpstanContracts\Tests\Fixtures\OpenPoint::$x may be uninitialized: PhpstanContracts\Tests\Fixtures\OpenPoint is not final and neither is its constructor, so a subclass constructor can skip the one that sets it. Make the class or the constructor final.'],
                'readsAPropertyPromotedByAParentConstructor' => ['contracts.unsupported: Property PhpstanContracts\Tests\Fixtures\Origin::$x may be uninitialized: it is promoted by the constructor of PhpstanContracts\Tests\Fixtures\OpenPoint, which the constructor of PhpstanContracts\Tests\Fixtures\Origin does not have to call.'],
                'readsAPropertyAssignedInTheConstructorBody' => ['contracts.unsupported: Property PhpstanContracts\Tests\Fixtures\LazyInit::$x may be uninitialized: only the properties promoted by the constructor are known to be set in every object.'],
                'readsAPropertyBehindAFinalConstructor' => [],
            ],
        ]);
    }

    /**
     * `new` runs a constructor that must be callable here and not deprecated, and later a destructor the proof does not see.
     */
    public function testNewOnlyRunsCodeThatIsModelled(): void
    {
        $this->assertOutcomes([
            Construction::class => [
                'buildsWithADeprecatedConstructor' => ['contracts.unsupported: Cannot instantiate OldStyle: its constructor is #[\Deprecated], and calling it triggers E_USER_DEPRECATED, which runs the error handler.'],
                'buildsWithAPrivateConstructor' => ['contracts.unsupported: Cannot instantiate Sealed: its constructor is not accessible here.'],
                'buildsAClassWithADestructor' => ['contracts.unsupported: Cannot instantiate Noisy: its destructor runs code that is not verified.'],
                'buildsAPlainClass' => [],
            ],
            Sealed::class => [
                'of' => [],
            ],
        ]);
    }
}
