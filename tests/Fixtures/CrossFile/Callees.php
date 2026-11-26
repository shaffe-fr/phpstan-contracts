<?php

declare(strict_types=1);

namespace PhpstanContracts\Tests\Fixtures\CrossFile;

use PhpstanContracts\Attribute\Requires;
use PhpstanContracts\Tests\Fixtures\ConstantInitializers;
use PhpstanContracts\Tests\Fixtures\DeprecatedLimits;
use PhpstanContracts\Tests\Fixtures\FloatLabel;
use PhpstanContracts\Tests\Fixtures\Mode;

/**
 * Callees of CrossFileInteractions, in another file: loops, non-linear arithmetic, exception
 * factories and constant reads, which must be handled as if they were written in the caller.
 */
final class Callees
{
    /**
     * @param int<0, 10000000> $totalDiscount
     * @param non-empty-list<int<0, 10000000>> $lineTotals
     * @return list<int>
     */
    #[Requires('count($lineTotals) <= 8')]
    #[Requires('array_sum($lineTotals) >= 1')]
    public static function split(int $totalDiscount, array $lineTotals): array
    {
        $subtotal = array_sum($lineTotals);
        $parts = [];
        foreach ($lineTotals as $lineTotal) {
            $parts[] = intdiv($totalDiscount * $lineTotal, $subtotal);
        }

        return $parts;
    }

    /** @param list<int<0, 1000>> $cents */
    #[Requires('count($cents) <= 3')]
    public static function boundedSum(array $cents): int
    {
        $total = 0;
        foreach ($cents as $cent) {
            $total += $cent;
        }

        return $total;
    }

    /** @param list<int<0, 1000>> $cents */
    public static function unboundedSum(array $cents): int
    {
        $total = 0;
        foreach ($cents as $cent) {
            $total += $cent;
        }

        return $total;
    }

    #[Requires('$perPage >= 1')]
    public static function roundDown(int $price, int $perPage): int
    {
        return intdiv($price, $perPage) * $perPage;
    }

    public static function guard(int $x): int
    {
        if ($x < 0) {
            throw Refusals::negative();
        }

        return $x;
    }

    public static function guardOpen(int $x): int
    {
        if ($x < 0) {
            throw Refusals::open();
        }

        return $x;
    }

    public static function deprecatedConstant(): int
    {
        return DeprecatedLimits::LIMIT;
    }

    public static function deprecatedCase(): Mode
    {
        return Mode::Legacy;
    }

    public static function floatLabel(): string
    {
        return ConstantInitializers::LABEL;
    }

    public static function floatBacking(string $label): FloatLabel
    {
        return FloatLabel::from($label);
    }

    /** @param int<0, DeprecatedLimits::DERIVED> $x */
    public static function boundedByAConstant(int $x): int
    {
        return $x;
    }
}
