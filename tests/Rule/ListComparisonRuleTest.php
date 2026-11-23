<?php

declare(strict_types=1);

namespace PhpstanContracts\Tests\Rule;

use PhpstanContracts\Tests\Fixtures\ListComparisonFixture;
use PhpstanContracts\Tests\Support\RuleFixtureTestCase;

final class ListComparisonRuleTest extends RuleFixtureTestCase
{
    public function testInArrayIdenticalAndCallsOverListsAndShapes(): void
    {
        $this->assertOutcomes([
            ListComparisonFixture::class => [
                ...self::inArrayOutcomes(),
                ...self::identicalOutcomes(),
                ...self::callOutcomes(),
            ],
        ]);
    }

    /**
     * @return array<string, list<string>>
     */
    private static function inArrayOutcomes(): array
    {
        return [
                'isBlocked' => [],
                'isBlockedButWrongWay' => ['contracts.ensuresViolated: Postcondition #[Ensures(\'implies(count($blockedSkus) === 0, $result)\')] can be violated. Counterexample: $blockedSkus = [], $sku = \'\' => $result = false'],
                'aFoundNeedleIsInTheElementRange' => [],
                'claimsAnIntIsNeverFound' => ['contracts.ensuresViolated: Postcondition #[Ensures(\'!$result\')] can be violated. Counterexample: $items = ['],
                'theFirstElementIsFound' => [],
                'looseOverInts' => [],
                'looseOverBools' => [],
                'overEnumCases' => [],
                'aNullNeedleOverNullableInts' => [],
                'anIntNeedleOverNullableInts' => [],
                'aNullableNeedleOverInts' => [],
                'aNullNeedleFindsANull' => [],
                'nanIsNeverFound' => [],
                'negativeZeroIsFoundAsZero' => [],
                'floatsAreComparedLikeIeee' => [],
                'floatsMissTheSecondValue' => ['contracts.ensuresViolated: Postcondition #[Ensures(\'$result === ($x === $y)\')] can be violated.'],
                'anEmptyListVariableContainsNothing' => [],
                'aBuiltList' => [],
                'overAProperty' => [],
                'overACallResult' => [],
                'withSeven' => [],
                'inAContractOverTheResult' => [],
                'negatedInAContract' => ['contracts.ensuresViolated: Postcondition #[Ensures(\'!in_array(8, $result, true)\')] can be violated. Counterexample: $items = [8] => $result = [8, 7]'],
                'aNeedleOfAnotherType' => ['contracts.unsupported: in_array() needs a needle of the element type of the list: got float for a list<int>. Convert the needle first, such as (float) $x.'],
                'aFloatNeedleWithoutStrict' => ['contracts.unsupported: in_array() needs a needle of the element type of the list: got float for a list<int>.'],
                'stringsWithoutStrict' => ['contracts.unsupported: in_array() without strict: true compares with ==, which juggles a string; pass true as the third argument.'],
                'nullableElementsWithoutStrict' => ['contracts.unsupported: in_array() without strict: true compares with ==, which juggles a ?int (null == 0 is true); pass true as the third argument.'],
                'overAShape' => ['contracts.unsupported: in_array() is only supported on a list or a list literal, not on an array shape; compare the values, such as $x === $totals[\'net\'] || $x === $totals[\'vat\'].'],
                'overAListOfObjects' => ['contracts.unsupported: in_array() on a list of objects compares them by identity with strict: true and property by property without it, which is not modelled; compare a field instead.'],
                'overAListOfShapes' => ['contracts.unsupported: in_array() on a list of array shapes compares them by identity with strict: true and property by property without it, which is not modelled; compare a field instead.'],
                'overANullableList' => ['contracts.unsupported: in_array() needs a list, not a ?list<int>, which may be null here: PHP throws a TypeError for in_array() on null. Check it first with `!== null`.'],
        ];
    }

    /**
     * @return array<string, list<string>>
     */
    private static function identicalOutcomes(): array
    {
        return [
                'comparesListsOfInts' => [],
                'sameCountIsNotIdentity' => ['contracts.ensuresViolated: Postcondition #[Ensures(\'implies(count($first) === count($second), $result)\')] can be violated.'],
                'oneElementEach' => [],
                'notIdentical' => [],
                'aListIsIdenticalToItself' => [],
                'comparesListsOfStrings' => [],
                'comparesListsOfNullableInts' => [],
                'comparesListsOfBools' => [],
                'comparesListsOfEnumCases' => [],
                'comparesToTheEmptyList' => [],
                'differsFromTheEmptyList' => [],
                'comparesToALiteral' => [],
                'matchesOnAList' => [],
                'matchesOnAListWrongly' => ['contracts.ensuresViolated: Postcondition #[Ensures(\'$result === 0\')] can be violated. Counterexample: $items = [1] => $result = 1'],
                'twoEmptyListsAreIdentical' => [],
                'comparesListsOfFloats' => ['contracts.unsupported: Lists of floats cannot be compared with ===: PHP first checks whether both are the same array in memory, so [NAN] === [NAN] is false while $a === $a is true for $a = [NAN]. Compare count() and the elements instead, such as count($a) === count($b) && $a[0] === $b[0].'],
                'comparesListsOfNullableFloats' => ['contracts.unsupported: Lists of floats cannot be compared with ==='],
                'comparesListsOfObjects' => ['contracts.unsupported: Lists of objects cannot be compared with ===: objects are compared by identity; compare the fields of the elements.'],
                'comparesListsOfShapes' => ['contracts.unsupported: Array shapes compared with === also compare the order of their keys and any extra keys'],
                'comparesShapes' => ['contracts.unsupported: Array shapes compared with === also compare the order of their keys and any extra keys, which the type array{...} does not fix; compare the elements instead, such as $a[\'net\'] === $b[\'net\'].'],
                'comparesListsLoosely' => ['contracts.unsupported: Arrays compared with == are compared loosely, with type juggling on every element ([1] == [true], [\'1e1\'] == [\'10\']); use === on lists, or compare the elements.'],
                'comparesListsWithNotEqual' => ['contracts.unsupported: Arrays compared with == are compared loosely'],
                'comparesListsWithTheDiamond' => ['contracts.unsupported: Arrays compared with == are compared loosely'],
                'ordersLists' => ['contracts.unsupported: Arrays cannot be ordered with <=: PHP compares them by count, then key by key, which is not modelled. Compare count($list) or the elements.'],
                'comparesListsOfDifferentElements' => ['contracts.unsupported: Lists of different element types (list<int> and list<float>) are not compared with ===: convert the elements first, or compare count() and the elements.'],
                'comparesAListWithAnInt' => ['contracts.unsupported: list<int> === int is always false in PHP; the operands have different types.'],
                'comparesAListWithNull' => [],
                'comparesANullableList' => ['contracts.unsupported: A list that may be null (?list<int> === list<int>) is not compared with === yet: check it with `!== null` first, then compare the lists.'],
        ];
    }

    /**
     * @return array<string, list<string>>
     */
    private static function callOutcomes(): array
    {
        return [
                'callsWithAList' => [],
                'sizeOf' => [],
                'callsWithAnEmptyList' => [],
                'callsWithABuiltList' => [],
                'callsWithTheWrongCount' => ['contracts.ensuresViolated: Postcondition #[Ensures(\'$result === 5\')] can be violated. Counterexample: no parameters => $result = 2'],
                'callsWithAShape' => [],
                'sumOf' => [],
                'callsWithAShapeLiteral' => [],
                'split' => [],
                'readsAShapeReturnedByACall' => [],
                'same' => [],
                'returnsAListReturnedByACall' => [],
                'countRates' => [],
                'aListOfIntsForAListOfFloats' => ['contracts.unsupported: Argument $rates of PhpstanContracts\Tests\Fixtures\ListComparisonFixture::countRates() is list<int>, but the parameter is list<float>.'],
                'aListOfFloatsForAListOfFloats' => [],
                'takesNullable' => [],
                'passesNullForANullableList' => [],
                'passesAListForANullableList' => [],
                'buildsADataClassWithAList' => [],
                'readsAnElementOfADataClassList' => [],
                'buildsADataClassWithAShape' => [],
                'buildsADataClassWithAListLiteral' => [],
                'readsAShapeLiteralInADataClass' => [],
                'readsAShapeLiteralInADataClassWrongly' => ['contracts.ensuresViolated: Postcondition #[Ensures(\'$result === 4\')] can be violated. Counterexample: no parameters => $result = 3'],
        ];
    }
}
