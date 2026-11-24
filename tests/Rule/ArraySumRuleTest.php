<?php
declare(strict_types=1);
namespace PhpstanContracts\Tests\Rule;
use PhpstanContracts\Tests\Fixtures\ArraySumFixture;
use PhpstanContracts\Tests\Support\RuleFixtureTestCase;
final class ArraySumRuleTest extends RuleFixtureTestCase
{
    public function testArraySumIsExactOnAListOfIntsAndEverythingElseIsRejectedWithAReason(): void
    {
        $this->assertOutcomes([
            ArraySumFixture::class => [
                'sumsTheAmounts' => [],
                'sumsAListOfAnyLength' => [],
                'claimsABoundTheSumCanExceed' => ['contracts.ensuresViolated: Postcondition #[Ensures(\'$result <= 9999999\')] can be violated. Counterexample: $amounts = ['],
                'sumsAnyInts' => ['contracts.overflow: Possible integer overflow: `array_sum($amounts)` can leave the PHP int range.'],
                'sumsNonNegativeInts' => ['contracts.overflow: Possible integer overflow: `array_sum($amounts)` can leave the PHP int range.'],
                'readsTheSumInAContract' => [],
                'claimsTheSumIsZero' => ['contracts.ensuresViolated: Postcondition #[Ensures(\'$result === array_sum($amounts)\')] can be violated. Counterexample: $amounts = ['],
                'sumsAnEmptyLiteral' => [],
                'sumsTheAmountsAndOneMore' => [],
                'sumsFloats' => ['contracts.unsupported: array_sum() of a list<float> is not supported: it returns the int 0 for an empty list and a float otherwise. Add the elements in a foreach, starting from 0.0.'],
                'sumsNullableInts' => ['contracts.unsupported: array_sum() is only supported on a list<int>: PHP converts null, bools and numeric strings to numbers, which is not modelled. Add the elements in a foreach.'],
                'sumsBooleans' => ['contracts.unsupported: array_sum() is only supported on a list<int>'],
                'sumsANullableList' => ['contracts.unsupported: array_sum() needs a list, not a ?list<int>, which may be null here'],
                'sumsAShape' => ['contracts.unsupported: array_sum() is only supported on a list<int>'],
            ],
        ]);
    }
}
