<?php

declare(strict_types=1);

namespace PhpstanContracts\Tests\Unit\Report;

use PhpstanContracts\Report\CostPrediction;
use PhpstanContracts\Report\UnknownExplainer;
use PhpstanContracts\Translate\Encoding;
use PhpstanContracts\Translate\LoopBound;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * The prediction reads the encoder's record and the limits, nothing else: every number here is the same
 * on every machine.
 */
final class CostPredictionTest extends TestCase
{
    public function testAMethodWithoutALoopHasNoStructureAndAMainRunOnlyWorstCase(): void
    {
        $encoding = new Encoding();
        $encoding->noteNonLinear('product', '$a * $b');

        $prediction = CostPrediction::of($encoding, 5, 10_000, 0);

        self::assertSame([], $prediction->loops);
        self::assertSame(0, $prediction->unrolledIterations);
        self::assertSame(1, $prediction->nonLinear);
        self::assertSame(0, $prediction->divisionsInLoops);
        self::assertSame(0, $prediction->cases);
        self::assertSame(5 * 10_000 + 10_000, $prediction->worstCaseMs, 'the main run and the kill margin; no retry without a loop');
        self::assertFalse($prediction->capped);
        self::assertFalse($prediction->isCostly());
    }

    public function testALoopCountsItsIterationsItsNestingAndItsDivisions(): void
    {
        $encoding = $this->splitOf(4, 2);

        $prediction = CostPrediction::of($encoding, 10, 1_000, 0, 4);

        self::assertSame([['line' => 7, 'bound' => 4, 'depth' => 0], ['line' => 9, 'bound' => 2, 'depth' => 1]], $prediction->loops);
        self::assertSame(4 + 4 * 2, $prediction->unrolledIterations, 'the inner loop is copied in every iteration of the outer one');
        self::assertSame(4 + 4 * 2, $prediction->nonLinear);
        self::assertSame(4 + 4 * 2, $prediction->divisionsInLoops);
        self::assertSame(6, $prediction->cases, 'a case per length 0..4 and one outside');
        // main: 10 x 1 s + 10 s; each of the 10 checks: the retry (1 s) and ceil(6 cases x 2 seeds / 4) = 3 waves (3 s)
        self::assertSame(10_000 + 10_000 + 10 * (1_000 + 3_000), $prediction->worstCaseMs);
    }

    public function testTheWorstCaseIsCutByTheBudgetOfTheMethod(): void
    {
        $prediction = CostPrediction::of($this->splitOf(4, 2), 10, 1_000, 30_000, 4);

        self::assertSame(30_000, $prediction->worstCaseMs);
        self::assertTrue($prediction->capped);

        $roomy = CostPrediction::of($this->splitOf(4, 2), 10, 1_000, 600_000, 4);
        self::assertSame(60_000, $roomy->worstCaseMs);
        self::assertFalse($roomy->capped);
    }

    public function testALoopTooLongForASplitHasNoCases(): void
    {
        $encoding = new Encoding();
        $encoding->needsSolverFallback = true;
        $encoding->noteSplitLength('(len l)', 20, '$l');
        $encoding->noteUnrolledLoop(3, new LoopBound(20, '$l', null, true));

        $prediction = CostPrediction::of($encoding, 4, 1_000, 0);

        self::assertSame(0, $prediction->cases);
        self::assertSame(4 * 1_000 + 10_000 + 4 * 1_000, $prediction->worstCaseMs, 'the retry only');
    }

    public function testThePredictionDoesNotDependOnTheMachine(): void
    {
        $first = CostPrediction::of($this->splitOf(8, 0), 20, 10_000, 60_000);
        $second = CostPrediction::of($this->splitOf(8, 0), 20, 10_000, 60_000);

        self::assertEquals($first, $second);
        self::assertSame($first->toArray(), $second->toArray());
    }

    /**
     * @return iterable<string, array{int, int, bool}>
     */
    public static function divisionsInLoops(): iterable
    {
        yield 'the split of 3 lines' => [3, 0, false];
        yield 'one fewer than the threshold' => [CostPrediction::COSTLY_DIVISIONS_IN_LOOPS - 1, 0, false];
        yield 'the threshold' => [CostPrediction::COSTLY_DIVISIONS_IN_LOOPS, 0, true];
        yield 'the split of 8 lines' => [8, 0, true];
        yield 'twenty products and no division' => [0, 20, false];
    }

    #[DataProvider('divisionsInLoops')]
    public function testOnlyDivisionsInLoopsMakeAMethodCostly(int $divisions, int $products, bool $costly): void
    {
        $encoding = new Encoding();
        $encoding->needsSolverFallback = true;
        $encoding->noteUnrolledLoop(3, new LoopBound(20, '$l', null, true));
        for ($index = 0; $index < max($divisions, $products); ++$index) {
            $encoding->enterIteration(3, $index, 20);
            if ($index < $divisions) {
                $encoding->noteNonLinear('division', 'intdiv($a, $b)');
            }
            if ($index < $products) {
                $encoding->noteNonLinear('product', '$a * $b');
            }
            $encoding->leaveIteration();
        }

        self::assertSame($costly, CostPrediction::of($encoding, 10, 10_000, 60_000)->isCostly());
    }

    public function testADivisionOutsideALoopIsNeverCostly(): void
    {
        $encoding = new Encoding();
        for ($index = 0; $index < 12; ++$index) {
            $encoding->noteNonLinear('modulo', '$a % $b');
        }

        self::assertFalse(CostPrediction::of($encoding, 10, 10_000, 60_000)->isCostly());
    }

    public function testTheForecastQuotesTheNumbersAndTheWaysOutWithoutAnyMeasuredTime(): void
    {
        $prediction = CostPrediction::of($this->splitOf(4, 2), 10, 1_000, 30_000, 4);
        $explainer = new UnknownExplainer();

        $forecast = $explainer->forecast($prediction, 3);

        self::assertSame(
            '3 of the 10 checks of this method were not tried. Predicted worst case for this method: 30 s (cut by contracts.methodBudgetMs), from its structure (10 checks, 12 unrolled iterations) and the limits, not a measure. Other ways out: a larger contracts.methodBudgetMs or simpler arithmetic (fewer products or divisions of variables).',
            $forecast,
        );
        self::assertStringStartsWith('1 of the 10 checks of this method was not tried.', $explainer->forecast($prediction, 1));
        self::assertStringStartsWith('Predicted worst case', $explainer->forecast($prediction, 0));
    }

    public function testTheCostlyMessageNamesTheLoopsTheBoundAndHowToIgnoreIt(): void
    {
        $encoding = $this->splitOf(8, 0);
        $prediction = CostPrediction::of($encoding, 51, 10_000, 60_000);

        $message = (new UnknownExplainer())->costly($prediction, $encoding);

        self::assertStringStartsWith('This method is likely to leave checks undecided (contracts.unknown): the proof unrolls the foreach at line 7 up to 8 iterations, and the copies of the loop body hold 8 divisions or modulos by a variable (8 non-linear operations in all)', $message);
        self::assertStringContainsString('Predicted worst case: 60 s, from the structure only and not a measure.', $message);
        self::assertStringContainsString('Try a smaller bound with #[Requires(\'count($lineTotals) <= 3\')], or simpler arithmetic, or a larger contracts.methodBudgetMs.', $message);
        self::assertStringEndsWith('ignore it by identifier with ignoreErrors if the proof is known to pass.', $message);
    }

    /**
     * A loop at line 7 of `$outer` iterations with one division per iteration and, when `$inner` is more
     * than 0, a loop at line 9 of `$inner` iterations inside it with one division per iteration.
     */
    private function splitOf(int $outer, int $inner): Encoding
    {
        $encoding = new Encoding();
        $encoding->needsSolverFallback = true;
        $encoding->noteSplitLength('(len $lineTotals)', $outer, '$lineTotals');
        $encoding->noteUnrolledLoop(7, new LoopBound($outer, '$lineTotals', null, true));
        for ($index = 0; $index < $outer; ++$index) {
            $encoding->enterIteration(7, $index, $outer);
            $encoding->noteNonLinear('division', 'intdiv($a, $b)');
            if ($inner > 0) {
                $encoding->noteUnrolledLoop(9, new LoopBound($inner, '$row', null, false));
                for ($other = 0; $other < $inner; ++$other) {
                    $encoding->enterIteration(9, $other, $inner);
                    $encoding->noteNonLinear('division', 'intdiv($c, $d)');
                    $encoding->leaveIteration();
                }
            }
            $encoding->leaveIteration();
        }

        return $encoding;
    }
}
