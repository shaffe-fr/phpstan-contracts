<?php

declare(strict_types=1);

namespace PhpstanContracts\Examples\RealWorld;

use PhpstanContracts\Attribute\Ensures;
use PhpstanContracts\Attribute\Requires;

/**
 * Totals over the lines of an order, with a loop over the list of lines.
 */
final class OrderTotals
{
    /**
     * @param list<OrderLine> $lines
     */
    #[Requires('count($lines) <= 20')]
    #[Ensures('$result >= 0')]
    #[Ensures('implies(count($lines) === 0, $result === 0)')]
    public static function total(array $lines): int
    {
        $total = 0;
        foreach ($lines as $line) {
            $total += $line->quantity * $line->unitCents;
        }

        return $total;
    }

    /**
     * The same total without a bound on the number of lines: the proof covers the
     * orders up to a number of lines only, which the prover says instead of
     * assuming.
     *
     * @param list<OrderLine> $lines
     */
    #[Ensures('$result >= 0')]
    public static function totalBroken(array $lines): int
    {
        $total = 0;
        foreach ($lines as $line) {
            $total += $line->quantity * $line->unitCents;
        }

        return $total;
    }

    /**
     * Position of the first line above the limit, or -1 when there is none. Empty
     * lines are skipped.
     *
     * @param list<OrderLine> $lines
     */
    #[Requires('count($lines) <= 20')]
    #[Ensures('$result >= -1')]
    #[Ensures('$result < count($lines)')]
    #[Ensures('implies(count($lines) === 0, $result === -1)')]
    public static function firstLineAbove(array $lines, int $limitCents): int
    {
        foreach ($lines as $i => $line) {
            if ($line->unitCents === 0) {
                continue;
            }
            if ($line->quantity * $line->unitCents > $limitCents) {
                return $i;
            }
        }

        return -1;
    }

    /**
     * The off-by-one of the index: it can point after the last line.
     *
     * @param list<OrderLine> $lines
     */
    #[Requires('count($lines) <= 10')]
    #[Ensures('$result < count($lines)')]
    public static function firstLineAboveBroken(array $lines, int $limitCents): int
    {
        foreach ($lines as $i => $line) {
            if ($line->quantity * $line->unitCents > $limitCents) {
                return $i + 1;
            }
        }

        return -1;
    }

    /**
     * Keeps the lines that cost something, in their order.
     *
     * @param list<OrderLine> $lines
     * @return list<OrderLine>
     */
    #[Requires('count($lines) <= 20')]
    #[Ensures('count($result) <= count($lines)')]
    public static function withoutEmptyLines(array $lines): array
    {
        $kept = [];
        foreach ($lines as $line) {
            if ($line->unitCents === 0) {
                continue;
            }
            $kept[] = $line;
        }

        return $kept;
    }

    /**
     * Claims that nothing is dropped, which is false as soon as a line is free.
     *
     * @param list<OrderLine> $lines
     * @return list<OrderLine>
     */
    #[Requires('count($lines) <= 20')]
    #[Ensures('count($result) === count($lines)')]
    public static function withoutEmptyLinesBroken(array $lines): array
    {
        $kept = [];
        foreach ($lines as $line) {
            if ($line->unitCents === 0) {
                continue;
            }
            $kept[] = $line;
        }

        return $kept;
    }
}

/**
 * A line of an order.
 */
final readonly class OrderLine
{
    public function __construct(
        /** @var int<1, 1000> */
        public int $quantity,
        /** @var int<0, 1000000> */
        public int $unitCents,
    ) {
    }
}
