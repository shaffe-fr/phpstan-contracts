<?php
declare(strict_types=1);
namespace PhpstanContracts\Tests\Fixtures;
use PhpstanContracts\Attribute\Ensures;
use PhpstanContracts\Attribute\Pure;
use PhpstanContracts\Attribute\Requires;
/**
 * `array_sum()` over a `list<int>`: exact when no prefix of the sum leaves the int range, an
 * overflow obligation otherwise, and a rejection for every list whose sum PHP converts.
 */
final class ArraySumFixture
{
    /**
     * @param list<int<0, 1000000>> $amounts
     */
    #[Requires('count($amounts) <= 10')]
    #[Ensures('$result >= 0 && $result <= 1000000 * count($amounts)')]
    public static function sumsTheAmounts(array $amounts): int
    {
        return array_sum($amounts);
    }
    /**
     * @param list<int<0, 1000000>> $amounts
     */
    #[Ensures('$result >= 0 && $result <= 1000000 * count($amounts)')]
    public static function sumsAListOfAnyLength(array $amounts): int
    {
        return array_sum($amounts);
    }
    /**
     * @param list<int<0, 1000000>> $amounts
     */
    #[Requires('count($amounts) <= 10')]
    #[Ensures('$result <= 9999999')]
    public static function claimsABoundTheSumCanExceed(array $amounts): int
    {
        return array_sum($amounts);
    }
    /**
     * @param list<int> $amounts
     */
    #[Pure]
    public static function sumsAnyInts(array $amounts): int
    {
        return array_sum($amounts);
    }
    /**
     * @param list<int<0, max>> $amounts
     */
    #[Requires('count($amounts) <= 3')]
    public static function sumsNonNegativeInts(array $amounts): int
    {
        return array_sum($amounts);
    }
    /**
     * @param list<int<0, 1000>> $amounts
     */
    #[Requires('count($amounts) <= 10')]
    #[Ensures('array_sum($amounts) <= 1000 * count($amounts)')]
    public static function readsTheSumInAContract(array $amounts): int
    {
        return 0;
    }
    /**
     * @param list<int<0, 1000>> $amounts
     */
    #[Requires('count($amounts) <= 10')]
    #[Ensures('$result === array_sum($amounts)')]
    public static function claimsTheSumIsZero(array $amounts): int
    {
        return 0;
    }
    #[Ensures('$result === 0')]
    public static function sumsAnEmptyLiteral(): int
    {
        return array_sum([]);
    }
    /**
     * @param list<int<0, 1000>> $amounts
     */
    #[Requires('count($amounts) <= 5')]
    #[Ensures('$result >= 0 && $result <= 6000')]
    public static function sumsTheAmountsAndOneMore(array $amounts, int $extra): int
    {
        $total = array_sum($amounts);
        return $total + 1000;
    }
    #[Pure]
    public static function sumsFloats(): float
    {
        return (float) array_sum([1.5, 2.5]);
    }
    /**
     * @param list<int|null> $amounts
     */
    #[Pure]
    public static function sumsNullableInts(array $amounts): int
    {
        return array_sum($amounts);
    }
    /**
     * @param list<bool> $flags
     */
    #[Pure]
    public static function sumsBooleans(array $flags): int
    {
        return array_sum($flags);
    }
    /**
     * @param list<int>|null $amounts
     */
    #[Pure]
    public static function sumsANullableList(?array $amounts): int
    {
        return array_sum($amounts);
    }
    /**
     * @param array{net: int, vat: int} $totals
     */
    #[Pure]
    public static function sumsAShape(array $totals): int
    {
        return array_sum($totals);
    }
}
