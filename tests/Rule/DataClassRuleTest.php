<?php

declare(strict_types=1);

namespace PhpstanContracts\Tests\Rule;

use PhpstanContracts\Tests\Fixtures\DataClassFixture;
use PhpstanContracts\Tests\Fixtures\Interval;
use PhpstanContracts\Tests\Fixtures\PriceContext;
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
                'buildsAPoint' => [],
                'namedArgumentsFollowTheirNames' => [],
                'swapsTheCoordinates' => [],
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
                'everyConstructorArgumentIsRequired' => ['contracts.unsupported: Every constructor argument of Point must be passed explicitly'],

                'inheritedStateThatIsNeverRead' => [],
                'buildsAnObjectWithInheritedState' => [],
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
                'readsInheritedState' => ['contracts.unsupported: Property PhpstanContracts\Tests\Fixtures\PriceContext::$_additional must be public and readonly'],
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
}
