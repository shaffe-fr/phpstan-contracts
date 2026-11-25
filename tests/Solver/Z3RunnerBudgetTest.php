<?php

declare(strict_types=1);

namespace PhpstanContracts\Tests\Solver;

use PhpstanContracts\Solver\CaseSplit;
use PhpstanContracts\Solver\ProofCache;
use PhpstanContracts\Solver\RunBudget;
use PhpstanContracts\Solver\RunBudgetExhausted;
use PhpstanContracts\Solver\UnknownReason;
use PhpstanContracts\Solver\Verdict;
use PhpstanContracts\Solver\Z3Runner;
use PhpstanContracts\Tests\Support\FakeClock;
use PhpstanContracts\Tests\Support\RequiresZ3;
use PHPUnit\Framework\TestCase;

/**
 * What the budgets do to the real solver. The slow question is a sum of three cubes that Z3 does not
 * decide within any limit of these tests, and the deadlines are seconds away from what Z3 needs for
 * the easy ones, so that no assertion depends on a race.
 */
final class Z3RunnerBudgetTest extends TestCase
{
    use RequiresZ3;

    private FakeClock $clock;

    /** @var list<string> */
    private array $directories = [];

    protected function setUp(): void
    {
        $this->clock = new FakeClock(100_000);
    }

    protected function tearDown(): void
    {
        foreach ($this->directories as $directory) {
            foreach (glob($directory . DIRECTORY_SEPARATOR . '*') ?: [] as $file) {
                @unlink($file);
            }
            @rmdir($directory);
        }
    }

    public function testTheBudgetCutsTheMainRunAndKeepsWhatWasAnswered(): void
    {
        $runner = new Z3Runner($this->requireZ3(), 60_000, clock: $this->clock);
        $script = $this->script($this->easySat(0), $this->easyUnsat(1), $this->slow(2));

        $start = microtime(true);
        $results = $runner->run($script, 3, $this->clock->nowMs() + 3_000);

        self::assertLessThan(30, microtime(true) - $start, 'the process is stopped when the budget is spent');
        self::assertSame(Verdict::Sat, $results[0]->verdict);
        self::assertSame('1', $results[0]->values['x']);
        self::assertSame(Verdict::Unsat, $results[1]->verdict, 'the answers printed before the cut are kept');
        self::assertSame(Verdict::Unknown, $results[2]->verdict, 'a question that was cut is never an answer');
        self::assertSame(UnknownReason::Budget, $results[2]->reason);
    }

    public function testAKilledRunNeverYieldsAVerdictForWhatItDidNotAnswer(): void
    {
        $runner = new Z3Runner($this->requireZ3(), 60_000, clock: $this->clock);

        $results = $runner->run($this->script($this->slow(0), $this->slow(1)), 2, $this->clock->nowMs() + 2_000);

        foreach ($results as $result) {
            self::assertSame(Verdict::Unknown, $result->verdict);
            self::assertSame(UnknownReason::Budget, $result->reason);
            self::assertSame([], $result->values);
        }
    }

    public function testABudgetThatIsAlreadySpentTriesNothing(): void
    {
        $runner = new Z3Runner($this->requireZ3(), 60_000, clock: $this->clock);

        $results = $runner->run($this->script($this->easySat(0), $this->easyUnsat(1)), 2, $this->clock->nowMs() - 1);

        self::assertSame([Verdict::Unknown, Verdict::Unknown], array_map(static fn ($result): Verdict => $result->verdict, $results));
        self::assertSame([UnknownReason::Budget, UnknownReason::Budget], array_map(static fn ($result): ?UnknownReason => $result->reason, $results));
    }

    public function testTheChecksThatAreSkippedHaveNoAnswerAndAreUnknownAsRemembered(): void
    {
        $runner = new Z3Runner($this->requireZ3(), 10_000);
        // The script holds the checks 0 and 2 only.
        $script = $this->script($this->easySat(0), $this->easyUnsat(2));

        $results = $runner->run($script, 3, null, [1]);

        self::assertSame(Verdict::Sat, $results[0]->verdict);
        self::assertSame(Verdict::Unknown, $results[1]->verdict);
        self::assertSame(UnknownReason::Remembered, $results[1]->reason);
        self::assertSame(Verdict::Unsat, $results[2]->verdict);
    }

    public function testASplitThatTheBudgetStoppedIsUnknownForThatReason(): void
    {
        $cases = CaseSplit::cases('n', 1) ?? [];
        $scripts = array_map(fn ($case): string => $this->script($this->slow(0)), $cases);
        $runner = new Z3Runner($this->requireZ3(), 60_000, parallelism: 2, clock: $this->clock);

        $outcome = $runner->runSplit($this->script($this->slow(0)), $cases, $scripts, $this->clock->nowMs() + 2_000);

        self::assertSame(Verdict::Unknown, $outcome->result->verdict);
        self::assertSame(UnknownReason::Budget, $outcome->result->reason);
        self::assertNotSame([], $outcome->undecided);
    }

    public function testASplitWhoseBudgetIsSpentStartsNoCase(): void
    {
        $cases = CaseSplit::cases('n', 1) ?? [];
        $scripts = array_map(fn ($case): string => $this->script($this->easySat(0)), $cases);
        $runner = new Z3Runner($this->requireZ3(), 60_000, clock: $this->clock);

        $outcome = $runner->runSplit($this->script($this->easySat(0)), $cases, $scripts, $this->clock->nowMs());

        self::assertSame(Verdict::Unknown, $outcome->result->verdict, 'the cases would have a model, so none was started');
        self::assertSame(UnknownReason::Budget, $outcome->result->reason);
    }

    public function testASplitThatIsUnknownWithoutABudgetKeepsNoReason(): void
    {
        $cases = CaseSplit::cases('n', 0) ?? [];
        $scripts = array_map(fn ($case): string => $this->script($this->slow(0)), $cases);
        $runner = new Z3Runner($this->requireZ3(), 500, parallelism: 2);

        $outcome = $runner->runSplit($this->script($this->slow(0)), $cases, $scripts);

        self::assertSame(Verdict::Unknown, $outcome->result->verdict);
        self::assertNull($outcome->result->reason, 'Z3 gave up at its own limit, which is a try in full');
    }

    public function testTheRunBudgetRefusesTheQuestionsThatComeAfterItIsSpent(): void
    {
        $budget = new RunBudget(1);
        $runner = new Z3Runner($this->requireZ3(), 10_000, runBudget: $budget);
        $script = $this->script($this->easySat(0));

        self::assertSame(Verdict::Sat, $runner->run($script, 1)[0]->verdict);
        self::assertGreaterThan(0, $budget->spentMs());
        self::assertTrue($budget->isExhausted());

        try {
            $runner->run($script, 1);
            self::fail('The run budget is spent: Z3 must not be asked.');
        } catch (RunBudgetExhausted $exhausted) {
            self::assertSame(1, $exhausted->limitMs);
            self::assertSame($budget->spentMs(), $exhausted->spentMs);
        }

        $this->expectException(RunBudgetExhausted::class);
        $runner->runSplit($script, CaseSplit::cases('n', 0) ?? [], [$script, $script]);
    }

    public function testAnAnswerFromTheProofCacheIsFreeEvenWhenTheRunBudgetIsSpent(): void
    {
        $directory = $this->directory();
        $script = $this->script($this->easySat(0));
        (new Z3Runner($this->requireZ3(), 10_000, new ProofCache($directory)))->run($script, 1);

        $spent = new RunBudget(1);
        $spent->spend(10);
        $runner = new Z3Runner($this->requireZ3(), 10_000, new ProofCache($directory), runBudget: $spent);

        self::assertSame(Verdict::Sat, $runner->run($script, 1)[0]->verdict);
    }

    public function testAnUndecidedCheckIsRememberedOnlyUnderTheSameKey(): void
    {
        $z3 = $this->requireZ3();
        $directory = $this->directory();
        $script = $this->script($this->slow(0));
        $runner = new Z3Runner($z3, 1_000, new ProofCache($directory));

        self::assertFalse($runner->isRemembered($script, 60_000));
        $runner->remember($script, 60_000);

        self::assertTrue($runner->isRemembered($script, 60_000));
        self::assertTrue((new Z3Runner($z3, 1_000, new ProofCache($directory)))->isRemembered($script, 60_000), 'it is on disk');
        self::assertFalse($runner->isRemembered($script . ' ', 60_000), 'another script');
        self::assertFalse($runner->isRemembered($script, 59_999), 'another method budget');
        self::assertFalse((new Z3Runner($z3, 1_001, new ProofCache($directory)))->isRemembered($script, 60_000), 'another timeout');
        self::assertFalse((new Z3Runner($z3, 1_000, new ProofCache(null)))->isRemembered($script, 60_000), 'no cache');
    }

    public function testARememberedCheckIsNeverReadAsAnAnswer(): void
    {
        $z3 = $this->requireZ3();
        $directory = $this->directory();
        $script = $this->script($this->easySat(0));
        $runner = new Z3Runner($z3, 10_000, new ProofCache($directory));

        $runner->remember($script, 60_000);

        self::assertSame(Verdict::Sat, $runner->run($script, 1)[0]->verdict, 'the mark is not a proof, and not a refutation either');
    }

    private function directory(): string
    {
        return $this->directories[] = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'contracts-budget-' . bin2hex(random_bytes(6));
    }

    private function easySat(int $check): string
    {
        return sprintf("(push 1)\n(assert (= x 1))\n(echo \"@@%d\")\n(check-sat)\n(get-value (x))\n(pop 1)", $check);
    }

    private function easyUnsat(int $check): string
    {
        return sprintf("(push 1)\n(assert (and (> x 5) (< x 3)))\n(echo \"@@%d\")\n(check-sat)\n(get-value (x))\n(pop 1)", $check);
    }

    private function slow(int $check): string
    {
        return sprintf("(push 1)\n(assert (= (+ (* n n n) (* y y y) (* z z z)) 33))\n(echo \"@@%d\")\n(check-sat)\n(get-value (x))\n(pop 1)", $check);
    }

    private function script(string ...$checks): string
    {
        return "(set-option :produce-models true)\n(declare-const x Int)\n(declare-const n Int)\n(declare-const y Int)\n(declare-const z Int)\n" . implode("\n", $checks) . "\n";
    }
}
