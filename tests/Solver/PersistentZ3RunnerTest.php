<?php

declare(strict_types=1);

namespace PhpstanContracts\Tests\Solver;

use PhpstanContracts\Solver\PersistentZ3Runner;
use PhpstanContracts\Solver\ProofCache;
use PhpstanContracts\Solver\RunBudget;
use PhpstanContracts\Solver\RunBudgetExhausted;
use PhpstanContracts\Solver\SolverFailure;
use PhpstanContracts\Solver\UnknownReason;
use PhpstanContracts\Solver\Verdict;
use PhpstanContracts\Tests\Support\FakeClock;
use PhpstanContracts\Tests\Support\RequiresZ3;
use PHPUnit\Framework\TestCase;

/**
 * What the persistent process does when a script goes wrong: every way it can fail must leave the
 * next script a fresh process with a fresh state, and none may turn a missing answer into a proof.
 * The slow question is a sum of three cubes that Z3 does not decide within any limit of these tests.
 */
final class PersistentZ3RunnerTest extends TestCase
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

    public function testTheBudgetCutsTheRunAndKeepsWhatWasAnswered(): void
    {
        $runner = new PersistentZ3Runner($this->requireZ3(), 60_000, clock: $this->clock);
        $script = $this->script($this->easySat(0), $this->easyUnsat(1), $this->slow(2));

        $start = microtime(true);
        $results = $runner->run($script, 3, $this->clock->nowMs() + 3_000);

        self::assertLessThan(30, microtime(true) - $start, 'the process is killed when the budget is spent, though the injected clock never moves');
        self::assertSame(Verdict::Sat, $results[0]->verdict);
        self::assertSame('1', $results[0]->values['x']);
        self::assertSame(Verdict::Unsat, $results[1]->verdict, 'the answers printed before the cut are kept');
        self::assertSame(Verdict::Unknown, $results[2]->verdict, 'a question that was cut is never an answer');
        self::assertSame(UnknownReason::Budget, $results[2]->reason);
    }

    public function testAKilledRunNeverYieldsAVerdictForWhatItDidNotAnswer(): void
    {
        $runner = new PersistentZ3Runner($this->requireZ3(), 60_000, clock: $this->clock);

        $results = $runner->run($this->script($this->slow(0), $this->slow(1)), 2, $this->clock->nowMs() + 2_000);

        foreach ($results as $result) {
            self::assertSame(Verdict::Unknown, $result->verdict);
            self::assertSame(UnknownReason::Budget, $result->reason);
            self::assertSame([], $result->values);
        }
    }

    public function testTheScriptAfterAKillGetsAFreshProcess(): void
    {
        $runner = new PersistentZ3Runner($this->requireZ3(), 60_000, clock: $this->clock);

        $runner->run($this->script($this->slow(0)), 1, $this->clock->nowMs() + 2_000);
        $results = $runner->run($this->script($this->easySat(0), $this->easyUnsat(1)), 2);

        self::assertSame(Verdict::Sat, $results[0]->verdict);
        self::assertSame('1', $results[0]->values['x']);
        self::assertSame(Verdict::Unsat, $results[1]->verdict);
    }

    public function testABudgetThatIsAlreadySpentTriesNothing(): void
    {
        $runner = new PersistentZ3Runner($this->requireZ3(), 60_000, clock: $this->clock);

        $results = $runner->run($this->script($this->easySat(0), $this->easyUnsat(1)), 2, $this->clock->nowMs() - 1);

        self::assertSame([Verdict::Unknown, Verdict::Unknown], array_map(static fn ($result): Verdict => $result->verdict, $results));
        self::assertSame([UnknownReason::Budget, UnknownReason::Budget], array_map(static fn ($result): ?UnknownReason => $result->reason, $results));
    }

    public function testTheChecksThatAreSkippedHaveNoAnswerAndAreUnknownAsRemembered(): void
    {
        $runner = new PersistentZ3Runner($this->requireZ3(), 10_000);
        // The script holds the checks 0 and 2 only.
        $script = $this->script($this->easySat(0), $this->easyUnsat(2));

        $results = $runner->run($script, 3, null, [1]);

        self::assertSame(Verdict::Sat, $results[0]->verdict);
        self::assertSame(Verdict::Unknown, $results[1]->verdict);
        self::assertSame(UnknownReason::Remembered, $results[1]->reason);
        self::assertSame(Verdict::Unsat, $results[2]->verdict);
    }

    public function testAScriptZ3CannotReadIsASolverFailureAndTheNextScriptIsFresh(): void
    {
        $runner = new PersistentZ3Runner($this->requireZ3(), 10_000);

        try {
            // `y` is never declared: Z3 skips the assertion and answers anyway, so the script would read as weaker than it is.
            $runner->run("(set-option :produce-models true)\n(declare-const x Int)\n(assert (> y 5))\n(echo \"@@0\")\n(check-sat)\n(get-value (x))\n", 1);
            self::fail('A script that Z3 rejected is never an answer.');
        } catch (SolverFailure) {
        }

        $results = $runner->run($this->script($this->easySat(0)), 1);
        self::assertSame(Verdict::Sat, $results[0]->verdict);
        self::assertSame('1', $results[0]->values['x']);
    }

    public function testAProcessThatEndsInTheMiddleOfAScriptIsASolverFailureAndTheNextScriptIsFresh(): void
    {
        $runner = new PersistentZ3Runner($this->requireZ3(), 10_000);

        try {
            $runner->run("(set-option :produce-models true)\n(declare-const x Int)\n(exit)\n(echo \"@@0\")\n(check-sat)\n", 1);
            self::fail('A process that ended before its answers is never an answer.');
        } catch (SolverFailure) {
        }

        $results = $runner->run($this->script($this->easyUnsat(0)), 1);
        self::assertSame(Verdict::Unsat, $results[0]->verdict);
    }

    public function testAGlobalOptionOfAScriptNeverReachesTheNextOne(): void
    {
        $runner = new PersistentZ3Runner($this->requireZ3(), 10_000);

        // A resource limit of 1 makes every check `unknown`, and Z3 keeps it across a `(reset)`.
        $limited = $runner->run("(set-option :produce-models true)\n(set-option :rlimit 1)\n(declare-const x Int)\n(assert (> (* x x) 2))\n(echo \"@@0\")\n(check-sat)\n", 1);
        self::assertSame(Verdict::Unknown, $limited[0]->verdict);

        $results = $runner->run($this->script($this->easySat(0)), 1);
        self::assertSame(Verdict::Sat, $results[0]->verdict, 'the limit of the previous script would have made this unknown');
    }

    public function testTheRunBudgetRefusesTheQuestionsThatComeAfterItIsSpent(): void
    {
        $budget = new RunBudget(1);
        $runner = new PersistentZ3Runner($this->requireZ3(), 10_000, runBudget: $budget);
        $script = $this->script($this->easySat(0));

        self::assertSame(Verdict::Sat, $runner->run($script, 1)[0]->verdict);
        self::assertGreaterThan(0, $budget->spentMs());

        $this->expectException(RunBudgetExhausted::class);
        $runner->run($script, 1);
    }

    public function testAnAnswerFromTheProofCacheIsFreeAndNeverComesFromADirtyProcess(): void
    {
        $directory = $this->directory();
        $z3 = $this->requireZ3();
        $script = $this->script($this->easySat(0));
        (new PersistentZ3Runner($z3, 10_000, new ProofCache($directory)))->run($script, 1);

        $spent = new RunBudget(1);
        $spent->spend(10);
        $cached = new PersistentZ3Runner($z3, 10_000, new ProofCache($directory), runBudget: $spent);

        self::assertSame(Verdict::Sat, $cached->run($script, 1)[0]->verdict, 'it comes from the cache, which a spent run budget does not refuse');

        // A script Z3 could not read is not written to the cache.
        $broken = "(set-option :produce-models true)\n(declare-const x Int)\n(assert (> y 5))\n(echo \"@@0\")\n(check-sat)\n";
        $runner = new PersistentZ3Runner($z3, 10_000, new ProofCache($directory));
        try {
            $runner->run($broken, 1);
            self::fail('A script that Z3 rejected is never an answer.');
        } catch (SolverFailure) {
        }
        $files = glob($directory . DIRECTORY_SEPARATOR . '*') ?: [];
        self::assertCount(1, $files, 'only the answer of the good script is on disk');
    }

    private function directory(): string
    {
        return $this->directories[] = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'contracts-persistent-' . bin2hex(random_bytes(6));
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
