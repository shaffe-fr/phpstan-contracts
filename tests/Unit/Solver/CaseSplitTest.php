<?php

declare(strict_types=1);

namespace PhpstanContracts\Tests\Unit\Solver;

use PhpstanContracts\Solver\CaseSplit;
use PHPUnit\Framework\TestCase;

final class CaseSplitTest extends TestCase
{
    public function testEachLengthUpToTheBoundIsACaseAndOneCaseHoldsTheRest(): void
    {
        $cases = CaseSplit::cases('(List_Int_len l@1)', 2);

        self::assertNotNull($cases);
        self::assertSame(
            [
                '(assert (= (List_Int_len l@1) 0))',
                '(assert (= (List_Int_len l@1) 1))',
                '(assert (= (List_Int_len l@1) 2))',
                '(assert (or (< (List_Int_len l@1) 0) (> (List_Int_len l@1) 2)))',
            ],
            array_map(static fn ($case): string => $case->assertion, $cases),
        );
        self::assertSame([0, 1, 2, null], array_map(static fn ($case): ?int => $case->length, $cases));
        self::assertSame(['0', '1', '2', 'outside 0..2'], array_map(static fn ($case): string => $case->description, $cases));
    }

    public function testTheCasesArePairwiseDisjointAndCoverEveryLength(): void
    {
        $cases = CaseSplit::cases('LEN', 5);
        self::assertNotNull($cases);

        // The same cases, read as PHP: every integer falls in exactly one.
        foreach (range(-3, 9) as $length) {
            $hits = 0;
            foreach ($cases as $case) {
                $hits += ($case->length === null ? $length < 0 || $length > 5 : $length === $case->length) ? 1 : 0;
            }
            self::assertSame(1, $hits, 'length ' . $length);
        }
    }

    public function testASplitMayHaveTwelveCasesAndNoMore(): void
    {
        self::assertCount(12, CaseSplit::cases('LEN', 10) ?? []);
        self::assertNull(CaseSplit::cases('LEN', 11), 'a bound of 11 needs 13 cases');
        self::assertNull(CaseSplit::cases('LEN', 100));
    }
}
