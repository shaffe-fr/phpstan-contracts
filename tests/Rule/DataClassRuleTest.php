<?php

declare(strict_types=1);

namespace PhpstanContracts\Tests\Rule;

use PhpstanContracts\Tests\Fixtures\DataClassFixture;
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
                'methodCallOnAnObject' => ['contracts.unsupported: Method calls'],
                'nullableObjectWithoutACheck' => ['contracts.unsupported: may be null here'],
                'everyConstructorArgumentIsRequired' => ['contracts.unsupported: Every constructor argument of Point must be passed explicitly'],
            ],
        ]);
    }
}
