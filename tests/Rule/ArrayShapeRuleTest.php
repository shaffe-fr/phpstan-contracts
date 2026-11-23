<?php

declare(strict_types=1);

namespace PhpstanContracts\Tests\Rule;

use PhpstanContracts\Tests\Fixtures\ArrayShapeFixture;
use PhpstanContracts\Tests\Support\RuleFixtureTestCase;

final class ArrayShapeRuleTest extends RuleFixtureTestCase
{
    public function testShapesAreProvenOrRefutedAndEverythingElseIsRejectedWithAReason(): void
    {
        $this->assertOutcomes([
            ArrayShapeFixture::class => [
                'splitsAnAmount' => [],
                'losesACent' => ['contracts.ensuresViolated: Postcondition #[Ensures(\'$result["net"] + $result["vat"] === $gross\')] can be violated. Counterexample: $gross = 0 => $result = [\'net\' => 0, \'vat\' => 1]'],
                'readsAField' => [],
                'aRequiresClauseReadsAField' => [],
                'literalInAnotherKeyOrder' => [],
                'builtFromParameters' => [],
                'nullInANullableField' => [],
                'valueInANullableField' => [],
                'coalescesAField' => [],
                'issetOnAField' => [],
                'narrowedByANullCheck' => [],
                'swapsTheFields' => [],
                'callsAMethodWithAShape' => [],
                'callsAMethodWithAnotherKeyOrder' => [],
                'netOf' => [],
                'vatOf' => [],
                'readsAShapeProperty' => [],
                'aFieldRangeIsAGoal' => ['contracts.returnType: The returned value can violate the declared return type `array{net: int<0, 50>}`'],
                'aFieldRangeIsAnAssumption' => [],
                'overflowsOnAField' => ['contracts.overflow: Possible integer overflow: `$totals[\'net\'] + $totals[\'vat\']`'],
                'readsAnUnknownKey' => ['contracts.unsupported: array{net: int, vat: int} has no key \'gross\'; its keys are \'net\' and \'vat\'.'],
                'readsWithAVariableKey' => ['contracts.unsupported: The key of an array shape must be a string literal naming one of its keys, such as $totals[\'net\'].'],
                'readsWithAnIntegerKey' => ['contracts.unsupported: The key of an array shape must be a string literal naming one of its keys'],
                'readsAsAProperty' => ['contracts.unsupported: An array shape is read with [\'net\'], not ->net.'],
                'readsAnOffsetOfAString' => ['contracts.unsupported: Reading [...] is only supported on lists and array shapes, not on a string; string offsets are not supported, use str_starts_with() or strlen().'],
                'readsAnOptionalShapeWithoutACheck' => ['contracts.unsupported: Reading [...] is only supported on lists and array shapes, not on a ?array{net: int}, which may be null here'],
                'comparesShapesStrictly' => ['contracts.unsupported: Array shapes compared with === also compare the order of their keys and any extra keys'],
                'comparesShapesLoosely' => ['contracts.unsupported: Arrays compared with == are compared loosely, with type juggling on every element'],
                'ordersShapes' => ['contracts.unsupported: Arrays cannot be ordered with <: PHP compares them by count, then key by key, which is not modelled.'],
                'matchesAShape' => ['contracts.unsupported: Array shapes compared with === also compare the order of their keys and any extra keys'],
                'countsAShape' => ['contracts.unsupported: count() of an array shape is not supported: the type array{...} does not rule out extra keys. count() is supported on lists.'],
                'searchesAShape' => ['contracts.unsupported: in_array() is only supported on a list or a list literal, not on an array shape'],
                'usesAnArrayFunction' => ['contracts.unsupported: Call to array_sum() is not part of the verified subset: of the array functions, only count() and in_array() are supported.'],
                'writesAKey' => ['contracts.unsupported: Writing to an array shape is not supported: build a new shape literal'],
                'addsToAKey' => ['contracts.unsupported: Writing to an array shape is not supported: build a new shape literal'],
                'appendsToAShape' => ['contracts.unsupported: Appending to an array shape is not supported: build a new shape literal.'],
                'writesANestedKey' => ['contracts.unsupported: Nested writes such as $a[$i][$j] = ... are not supported'],
                'destructuresAShape' => ['contracts.unsupported: Destructuring ([$a, $b] = ...) is not supported'],
                'unsetsAKey' => ['contracts.unsupported'],
                'aListLiteral' => [],
                'aLiteralMixingKeysAndNoKeys' => ['contracts.unsupported: An array literal is either a list without keys, such as [1, 2], or a shape with string keys, such as [\'net\' => 1]; mixing the two is not supported.'],
                'aLiteralWithAnIntegerKey' => ['contracts.unsupported: Integer keys, and string keys that PHP converts to integers such as \'1\', are not supported in array literals'],
                'aLiteralWithAnIntegerLikeKey' => ['contracts.unsupported: Integer keys, and string keys that PHP converts to integers such as \'1\', are not supported in array literals'],
                'aLiteralWithAVariableKey' => ['contracts.unsupported: The keys of an array literal must be string literals, such as [\'net\' => $net].'],
                'aLiteralWithADuplicateKey' => ['contracts.unsupported: Key \'net\' appears twice in this array literal; PHP keeps the last value. Write it once.'],
                'aLiteralWithASpread' => ['contracts.unsupported: Only a list<T> can be spread in a list literal, not `$other` (array{net: int}): PHP keeps the keys of other arrays'],
                'aLiteralWithAReference' => ['contracts.unsupported: References in array literals are not supported'],
                'aVariableHoldingNull' => ['contracts.unsupported: Cannot infer a type for $keyed'],
                'aVariableThatChangesItsShape' => ['contracts.unsupported: Cannot use a array{note: null} where a array{note: string} is expected.'],
                'aNullInsideChosenBranches' => ['contracts.unsupported: A array{note: null} with a null value cannot be chosen between branches'],
                'assignsANullToATypedVariable' => [],
                'anOptionalKey' => ['contracts.unsupported: Type array{net: int, vat?: int} is not supported: the optional key vat may be missing, which is not modelled.'],
                'aTuple' => ['contracts.unsupported: Type array{int, string} is not supported: array shapes are only supported with string keys.'],
                'anEmptyShape' => ['contracts.unsupported: Type array{} is not supported: declare list<T> for a list that may be empty.'],
                'arbitraryKeys' => ['contracts.unsupported: Type array<string, int> is not supported in verified methods: an array with arbitrary keys is not modelled. Use list<T> for a sequence, or an array shape with string keys such as array{net: int, vat: int}.'],
                'aUnionOfShapes' => ['contracts.unsupported: Type array{net: int}|array{vat: int} is not supported in verified methods (supported:'],
                'aUnionFieldType' => ['contracts.unsupported: Type float|string is not supported in verified methods'],
                'aFloatFieldInAnInput' => ['contracts.unsupported: Type array{rate: float} is not supported for an input: PHP does not convert the values of an array'],
                'aNullableFloatFieldInAnInput' => ['contracts.unsupported: Type array{rate: float|null} is not supported for an input'],
                'readsAFloatShapePropertyOfADataClass' => ['contracts.unsupported: Property PhpstanContracts\\Tests\\Fixtures\\FloatShapeHolder::$rates cannot be read in a verified method: Type array{rate: float} is not supported for an input'],
                'aFloatFieldBuiltInTheBody' => [],
                'shapesWithDistinctNonUtf8KeysAreDistinctTypes' => ['contracts.unsupported: Branches have different types'],
            ],
        ]);
    }
}
