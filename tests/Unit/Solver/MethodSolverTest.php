<?php

declare(strict_types=1);

namespace PhpstanContracts\Tests\Unit\Solver;

use PhpstanContracts\Smt\Smt;
use PhpstanContracts\Smt\Sort;
use PhpstanContracts\Solver\MethodSolver;
use PhpstanContracts\Solver\RunBudgetExhausted;
use PhpstanContracts\Solver\ScriptRenderer;
use PhpstanContracts\Solver\SolverFailure;
use PhpstanContracts\Solver\SolverResult;
use PhpstanContracts\Solver\SplitOutcome;
use PhpstanContracts\Solver\UnknownReason;
use PhpstanContracts\Solver\Verdict;
use PhpstanContracts\Tests\Support\FakeCheckRunner;
use PhpstanContracts\Tests\Support\FakeClock;
use PhpstanContracts\Translate\Encoding;
use PhpstanContracts\Translate\EnsuresCheck;
use PhpstanContracts\Translate\Obligation;
use PhpstanContracts\Translate\ObligationKind;
use PHPUnit\Framework\TestCase;

/**
 * The budget and the memory of undecided checks, without Z3 and without waiting: the solver is a
 * double that counts the questions, and the clock moves when a test moves it. The encoding has three
 * checks: the assumptions (0), an obligation (1) and a postcondition (2).
 */
final class MethodSolverTest extends TestCase
{
    private FakeClock $clock;

    private FakeCheckRunner $runner;

    private ScriptRenderer $renderer;

    protected function setUp(): void
    {
        $this->clock = new FakeClock();
        $this->runner = new FakeCheckRunner();
        $this->renderer = new ScriptRenderer();
    }

    public function testACheckLeftUnknownByTheWholeStrategyIsRememberedAndNotAskedAgain(): void
    {
        $encoding = $this->encoding();
        $this->runner->onRun = fn (string $script, int $count): array => $count === 1
            ? [self::unknown()]
            : [new SolverResult(Verdict::Sat, ['x@1' => '1']), self::unknown(), new SolverResult(Verdict::Unsat)];
        $this->runner->onSplit = fn (): SplitOutcome => new SplitOutcome(self::unknown(), [0, 1, 2, 'outside 0..2']);

        $first = $this->solver()->solve($encoding);

        self::assertSame([$this->renderer->renderSingleCheck($encoding, 1) => 60_000], $this->runner->remembered, 'only the undecided check, under the budget of the run');
        self::assertSame([0, 1, 2, 'outside 0..2'], $first->undecidedLengths[1]);

        $runs = count($this->runner->runs);
        $splits = $this->runner->splits;
        $second = $this->solver()->solve($encoding);

        self::assertSame($runs + 1, count($this->runner->runs), 'only the main run asks again, for the checks that were not undecided');
        self::assertSame([1], $this->runner->runs[$runs][3], 'the script of the main run does not hold the remembered check');
        self::assertSame($splits, $this->runner->splits, 'the split is not asked again');
        self::assertSame(Verdict::Unknown, $second->results[1]->verdict);
        self::assertSame(UnknownReason::Remembered, $second->results[1]->reason);
    }

    public function testWhenEveryCheckIsRememberedNothingIsAsked(): void
    {
        $encoding = $this->encoding();
        $this->runner->onRun = fn (string $script, int $count): array => array_fill(0, $count, self::unknown());
        $this->runner->onSplit = fn (): SplitOutcome => new SplitOutcome(self::unknown(), [0]);
        $this->solver()->solve($encoding);
        self::assertCount(3, $this->runner->remembered);

        $this->runner->onRun = null;
        $this->runner->onSplit = null;
        $runs = count($this->runner->runs);
        $splits = $this->runner->splits;
        $answer = $this->solver()->solve($encoding);

        self::assertSame($runs, count($this->runner->runs), 'no Z3 process for a check that is remembered');
        self::assertSame($splits, $this->runner->splits);
        foreach ($answer->results as $result) {
            self::assertSame(Verdict::Unknown, $result->verdict);
            self::assertSame(UnknownReason::Remembered, $result->reason);
        }
    }

    public function testACheckThatAnEarlierRunDecidedIsNeverRemembered(): void
    {
        $this->runner->onRun = fn (string $script, int $count): array => $count === 1
            ? [new SolverResult(Verdict::Unsat)]
            : [self::unknown(), self::unknown(), self::unknown()];
        $this->runner->onSplit = fn (): SplitOutcome => new SplitOutcome(new SolverResult(Verdict::Unsat));

        $answer = $this->solver()->solve($this->encoding());

        self::assertSame([], $this->runner->remembered);
        self::assertSame([Verdict::Unsat, Verdict::Unsat, Verdict::Unsat], array_map(static fn (SolverResult $result): Verdict => $result->verdict, $answer->results));
    }

    public function testTheMemoryIsNotUsedWhenItIsOff(): void
    {
        $encoding = $this->encoding();
        $this->runner->remembered = [$this->renderer->renderSingleCheck($encoding, 1) => 60_000];
        $this->runner->onRun = fn (string $script, int $count): array => array_fill(0, $count, self::unknown());
        $this->runner->onSplit = fn (): SplitOutcome => new SplitOutcome(self::unknown(), [0]);
        $before = $this->runner->remembered;

        $answer = $this->solver(rememberUndecided: false)->solve($encoding);

        self::assertSame(0, $this->runner->queries, 'nothing is looked up');
        self::assertSame($before, $this->runner->remembered, 'nothing is written');
        self::assertSame([], $this->runner->runs[0][3], 'the remembered check is asked like the others');
        self::assertNull($answer->results[1]->reason);
    }

    public function testTheMemoryIsNotUsedWithoutACache(): void
    {
        $this->runner->enabled = false;
        $this->runner->onRun = fn (string $script, int $count): array => array_fill(0, $count, self::unknown());
        $this->runner->onSplit = fn (): SplitOutcome => new SplitOutcome(self::unknown(), [0]);

        $this->solver()->solve($this->encoding());

        self::assertSame(0, $this->runner->queries);
        self::assertSame([], $this->runner->remembered);
    }

    public function testAMethodWithoutALoopIsRememberedAfterTheMainRun(): void
    {
        $encoding = $this->encoding(loop: false);
        $this->runner->onRun = fn (string $script, int $count): array => [new SolverResult(Verdict::Sat), self::unknown(), new SolverResult(Verdict::Unsat)];

        $this->solver()->solve($encoding);

        self::assertCount(1, $this->runner->runs, 'there is no retry without a loop');
        self::assertSame([$this->renderer->renderSingleCheck($encoding, 1) => 60_000], $this->runner->remembered);
    }

    public function testTheBudgetThatRanOutBeforeTheRetryLeavesTheChecksNotTriedAndNotRemembered(): void
    {
        $this->runner->onRun = function (): array {
            $this->clock->advance(5_000);

            return [new SolverResult(Verdict::Sat), self::unknown(), self::unknown()];
        };
        $this->runner->onSplit = fn (): SplitOutcome => throw new \LogicException('The budget was spent.');

        $answer = $this->solver(methodBudgetMs: 1_000)->solve($this->encoding());

        self::assertCount(1, $this->runner->runs, 'no retry once the budget is spent');
        self::assertSame(0, $this->runner->splits);
        self::assertSame(UnknownReason::Budget, $answer->results[1]->reason);
        self::assertSame(UnknownReason::Budget, $answer->results[2]->reason);
        self::assertSame(Verdict::Sat, $answer->results[0]->verdict, 'what was answered stays answered');
        self::assertSame([], $this->runner->remembered, 'a check that was not tried is not undecided');
    }

    public function testTheBudgetThatRanOutBeforeTheSplitLeavesTheCheckNotTriedAndNotRemembered(): void
    {
        $this->runner->onRun = function (string $script, int $count): array {
            if ($count === 1) {
                $this->clock->advance(2_000);

                return [self::unknown()];
            }

            return [new SolverResult(Verdict::Sat), self::unknown(), new SolverResult(Verdict::Unsat)];
        };
        $this->runner->onSplit = fn (): SplitOutcome => throw new \LogicException('The budget was spent.');

        $answer = $this->solver(methodBudgetMs: 1_000)->solve($this->encoding());

        self::assertCount(2, $this->runner->runs);
        self::assertSame(0, $this->runner->splits);
        self::assertSame(UnknownReason::Budget, $answer->results[1]->reason);
        self::assertSame([], $this->runner->remembered);
    }

    public function testEveryRetryAndSplitGetsTheDeadlineOfTheMethod(): void
    {
        $deadlines = [];
        $this->runner->onRun = function (string $script, int $count, ?int $deadline) use (&$deadlines): array {
            $deadlines[] = $deadline;

            return $count === 1 ? [self::unknown()] : [new SolverResult(Verdict::Sat), self::unknown(), new SolverResult(Verdict::Unsat)];
        };
        $this->runner->onSplit = function (string $original, array $cases, array $scripts, ?int $deadline) use (&$deadlines): SplitOutcome {
            $deadlines[] = $deadline;

            return new SplitOutcome(self::unknown(), [0]);
        };

        $this->solver(methodBudgetMs: 1_000)->solve($this->encoding());

        self::assertSame([11_000, 11_000, 11_000], $deadlines, 'the clock started at 10000 and the budget is 1000');
    }

    public function testASplitThatTheBudgetCutIsNotRemembered(): void
    {
        $this->runner->onRun = fn (string $script, int $count): array => $count === 1 ? [self::unknown()] : [new SolverResult(Verdict::Sat), self::unknown(), new SolverResult(Verdict::Unsat)];
        $this->runner->onSplit = fn (): SplitOutcome => new SplitOutcome(new SolverResult(Verdict::Unknown, [], UnknownReason::Budget), [0, 1]);

        $answer = $this->solver()->solve($this->encoding());

        self::assertSame(UnknownReason::Budget, $answer->results[1]->reason);
        self::assertSame([], $this->runner->remembered);
    }

    public function testARetryThatFailsIsNotRememberedAndKeepsTheFirstAnswer(): void
    {
        $this->runner->onRun = function (string $script, int $count): array {
            if ($count === 1) {
                throw new SolverFailure('crash');
            }

            return [new SolverResult(Verdict::Sat), self::unknown(), new SolverResult(Verdict::Unsat)];
        };

        $answer = $this->solver()->solve($this->encoding());

        self::assertSame(Verdict::Unknown, $answer->results[1]->verdict);
        self::assertNull($answer->results[1]->reason);
        self::assertSame([], $this->runner->remembered, 'a retry that failed did not try the check');
    }

    public function testTheRunBudgetSpentDuringTheRetryLeavesTheCheckNotTried(): void
    {
        $this->runner->onRun = function (string $script, int $count): array {
            if ($count === 1) {
                throw new RunBudgetExhausted(5_000, 4_000);
            }

            return [new SolverResult(Verdict::Sat), self::unknown(), new SolverResult(Verdict::Unsat)];
        };

        $answer = $this->solver()->solve($this->encoding());

        self::assertSame(UnknownReason::Budget, $answer->results[1]->reason);
        self::assertSame([], $this->runner->remembered);
    }

    public function testTheRunBudgetSpentBeforeTheMainRunIsReportedToTheCaller(): void
    {
        $this->runner->onRun = fn (): array => throw new RunBudgetExhausted(5_000, 4_000);

        $this->expectException(RunBudgetExhausted::class);
        $this->solver()->solve($this->encoding());
    }

    public function testAGenuineFailureOfTheMainRunIsNotTurnedIntoUnknown(): void
    {
        $this->runner->onRun = fn (): array => throw new SolverFailure('crash');

        $this->expectException(SolverFailure::class);
        $this->solver()->solve($this->encoding());
    }

    public function testAMethodBudgetOfZeroIsNoLimit(): void
    {
        $this->runner->onRun = function (string $script, int $count, ?int $deadline): array {
            self::assertNull($deadline);
            $this->clock->advance(10_000_000);

            return $count === 1 ? [self::unknown()] : [new SolverResult(Verdict::Sat), self::unknown(), new SolverResult(Verdict::Unsat)];
        };
        $this->runner->onSplit = fn (): SplitOutcome => new SplitOutcome(new SolverResult(Verdict::Unsat));

        $answer = $this->solver(methodBudgetMs: 0)->solve($this->encoding());

        self::assertSame(Verdict::Unsat, $answer->results[1]->verdict, 'the retry and the split are asked however long the main run took');
    }

    public function testANegativeBudgetIsRefused(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->solver(methodBudgetMs: -1);
    }

    private function solver(int $methodBudgetMs = 60_000, bool $rememberUndecided = true): MethodSolver
    {
        return new MethodSolver($this->runner, $this->renderer, $this->clock, $methodBudgetMs, $rememberUndecided);
    }

    private static function unknown(): SolverResult
    {
        return new SolverResult(Verdict::Unknown);
    }

    private function encoding(bool $loop = true): Encoding
    {
        $encoding = new Encoding();
        $x = $encoding->declareInput('x', Sort::int());
        $encoding->assert(Smt::compare('>', $x, Smt::int(-5)));
        $encoding->declareResult(Sort::int());
        $encoding->addObligation(new Obligation(ObligationKind::DivisionByZero, 3, '', Smt::bool(true), Smt::compare('>', $x, Smt::int(0))));
        $encoding->ensures[] = new EnsuresCheck('$result > 1', 5, Smt::compare('>', $x, Smt::int(1)));
        if ($loop) {
            $encoding->needsSolverFallback = true;
            $encoding->noteSplitLength('(List_Int_len l@1)', 2, '$l');
        }

        return $encoding;
    }
}
