<?php

declare(strict_types=1);

namespace PhpstanContracts\Tests\Rule;

use PhpstanContracts\Tests\Fixtures\ForeachFixture;
use PhpstanContracts\Tests\Support\RuleFixtureTestCase;

final class ForeachRuleTest extends RuleFixtureTestCase
{
    public function testForeachOverAListIsProvenOrRefutedAndEverythingElseIsRejectedWithAReason(): void
    {
        $this->assertOutcomes([
            ForeachFixture::class => [
                'sumsTheElements' => [],
                'anEmptyListRunsTheBodyZeroTimes' => [],
                'sumsTheKeys' => [],
                'sumsTheKeysWrongly' => ['contracts.ensuresViolated: Postcondition #[Ensures(\'$result === 0\')] can be violated. Counterexample: $amounts = ['],
                'countsTheLargeAmounts' => [],
                'weighsEachAmountWithAMatch' => [],
                'claimsAMaximumTheElementsCanExceed' => ['contracts.ensuresViolated: Postcondition #[Ensures(\'$result <= 999\')] can be violated. Counterexample: $amounts = ['],
                'keepsTheLastKeyAndValue' => [],
                'readsAVariableOnlyTheLoopDefines' => ['contracts.unsupported: Variable $seen is not defined at this point.'],
                'changesTheTypeOfAnAccumulator' => ['contracts.unsupported: Variable $total changes type from int to float.'],
                'boundsTheListWithLessThan' => [],
                'boundsTheListWithTheConstantFirst' => [],
                'boundsTheListWithGreaterThan' => [],
                'boundsTheListWithItsExactSize' => [],
                'boundsTheListWithAClassConstant' => [],
                'usesTheSmallestBoundOfTheList' => [],
                'claimsOneLessThanTheBoundAllows' => ['contracts.ensuresViolated: Postcondition #[Ensures(\'$result <= 1999\')] can be violated. Counterexample: $amounts = [1000, 1000]'],
                'hasNoBound' => ['contracts.loopBound: The proof unrolls this foreach up to 10 iterations, and $amounts can have more. This is a limit of the proof, not a bug in the code. If the real maximum is known, add #[Requires(\'count($amounts) <= 50\')]; otherwise raise contracts.loopUnroll. Counterexample: $amounts = a list of 11 elements starting with ['],
                'hasABoundAboveTheDefault' => [],
                'loopsOverAListBuiltFromABoundedOne' => [],
                'loopsOverAListTheBoundDoesNotMention' => ['contracts.loopBound: The proof unrolls this foreach up to 3 iterations, the bound of #[Requires(\'count($weights) <= 3\')], and $amounts can have more. This is a limit of the proof, not a bug in the code. If the real maximum is known, add #[Requires(\'count($amounts) <= 3\')], or raise the bound of the #[Requires] above. Counterexample: $amounts = ['],
                'loopsOverALocalListTheBoundDoesNotMention' => ['contracts.loopBound: The proof unrolls this foreach up to 3 iterations, the bound of #[Requires(\'count($weights) <= 3\')], and $copy can have more. This is a limit of the proof, not a bug in the code. If the real maximum is known, bound the list it is built from with #[Requires], or raise the bound of the #[Requires] above. Counterexample: $weights = ['],
                'loopsOverAListLiteral' => [],
                'returnsFromTheBody' => [],
                // One diagnostic for the location, with the first iteration that fails, although every iteration can overflow.
                'overflowsInTheSecondIteration' => ['contracts.overflow: Possible integer overflow: `$total + $amount` can leave the PHP int range, in iteration 2 (key 1) of the foreach at line '],
                'readsOneElementTooFar' => ['contracts.undefinedKey: `$amounts[$index + 1]` can read a key that does not exist'],
                'overTheUnrollingBudget' => ['contracts.unsupported: This foreach would be unrolled 500 times, more than contracts.maxLoopUnroll (100). Bound the list more tightly with #[Requires], or raise contracts.maxLoopUnroll.'],
                'whileLoop' => ['contracts.unsupported: Only foreach over a list is supported: a while, do or for loop needs a termination argument, which is not supported yet. Iterate the list with foreach ($list as $i => $item), or read it with count($list) and $list[$i].'],
                'byReference' => ['contracts.unsupported: foreach by reference (&$item) changes the list in place and leaves $item a reference to its last element, which is not modelled. Iterate by value and build a new list with $out[] = $value.'],
                'byReferenceWithAKey' => ['contracts.unsupported: foreach by reference (&$item) changes the list in place'],
                'destructuresWithBrackets' => ['contracts.unsupported: Destructuring in foreach is not supported: iterate with foreach ($list as $item) and read the elements, such as $item[\'net\'].'],
                'destructuresWithList' => ['contracts.unsupported: Destructuring in foreach is not supported: iterate with foreach ($list as $item) and read the elements, such as $item[\'net\'].'],
                'writesAnElementOfTheObject' => ['contracts.unsupported: The key and the value of a foreach must be local variables.'],
                'usesTheSameVariableTwice' => ['contracts.unsupported: The key and the value of this foreach are both $x; use two different variables.'],
                'overAShape' => ['contracts.unsupported: foreach over an array shape is not supported: its keys are not a list and its values can have different types. Read each key, such as $totals[\'net\'].'],
                'overANullableList' => ['contracts.unsupported: foreach over $amounts, which may be null here: PHP warns "foreach() argument must be of type array|object" and skips the loop. Check it first with !== null.'],
                'overAString' => ['contracts.unsupported: foreach is only supported over a list<T>, not over a string.'],
                'overAnEmptyLiteral' => ['contracts.unsupported: This list is always empty, so the loop never runs; remove the loop.'],
                'boundsTheListWithAGuard' => [],
                'overAnEmptyVariable' => ['contracts.unsupported: This list is always empty, so the loop never runs; remove the loop.'],
            ],
        ]);
    }
}
