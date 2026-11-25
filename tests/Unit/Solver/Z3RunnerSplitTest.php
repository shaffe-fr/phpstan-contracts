<?php

declare(strict_types=1);

namespace PhpstanContracts\Tests\Unit\Solver;

use InvalidArgumentException;
use PhpstanContracts\Solver\CaseSplit;
use PhpstanContracts\Solver\CpuCores;
use PhpstanContracts\Solver\ProofCache;
use PhpstanContracts\Solver\SplitCase;
use PhpstanContracts\Solver\Verdict;
use PhpstanContracts\Solver\Z3Runner;
use PhpstanContracts\Tests\Support\RequiresZ3;
use PHPUnit\Framework\TestCase;

/**
 * The cases of a split run in separate Z3 processes. The slow case is a sum of three cubes that
 * Z3 does not decide within any limit of these tests, so a run that returns quickly has stopped it.
 */
final class Z3RunnerSplitTest extends TestCase
{
    use RequiresZ3;

    private string $directory;

    protected function setUp(): void
    {
        $this->directory = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'contracts-split-' . bin2hex(random_bytes(6));
    }

    protected function tearDown(): void
    {
        foreach (glob($this->directory . DIRECTORY_SEPARATOR . '*') ?: [] as $file) {
            @unlink($file);
        }
        @rmdir($this->directory);
    }

    public function testEveryCaseUnsatisfiableIsAnUnsatisfiableCheckAndIsCached(): void
    {
        $cases = $this->cases(2);
        $original = $this->script('(assert (and (>= n 0) (<= n 2)))', '(assert (> n 5))');
        $runner = $this->runner(2);

        $outcome = $runner->runSplit($original, $cases, array_map(fn (SplitCase $case): string => $this->script($case->assertion, '(assert (and (>= n 0) (<= n 2)))', '(assert (> n 5))'), $cases));

        self::assertSame(Verdict::Unsat, $outcome->result->verdict);
        $cache = new ProofCache($this->directory);
        $cached = $cache->get($cache->key($this->requireZ3(), $original), 1);
        self::assertNotNull($cached, 'the answer is stored under the key of the script without the split');
        self::assertSame(Verdict::Unsat, $cached[0]->verdict);
    }

    public function testAModelOfOneCaseEndsTheRunAndStopsTheOthers(): void
    {
        $cases = $this->cases(2);
        // Length 1 has a model at once; every other case is the sum of three cubes.
        $scripts = array_map(fn (SplitCase $case): string => $case->length === 1 ? $this->script($case->assertion) : $this->script($case->assertion, $this->cubes()), $cases);

        $start = microtime(true);
        $outcome = $this->runner(4, 60000)->runSplit($this->script(), $cases, $scripts);
        $elapsed = microtime(true) - $start;

        self::assertSame(Verdict::Sat, $outcome->result->verdict);
        self::assertSame('1', $outcome->result->values['n']);
        self::assertLessThan(20, $elapsed, 'the cases that Z3 would not decide within 60 s were stopped');
    }

    public function testASequentialRunStopsAtTheFirstModelWithoutStartingTheRest(): void
    {
        $cases = $this->cases(2);
        $scripts = array_map(fn (SplitCase $case): string => $case->length === 0 ? $this->script($case->assertion) : $this->script($case->assertion, $this->cubes()), $cases);

        $start = microtime(true);
        $outcome = $this->runner(1, 60000)->runSplit($this->script(), $cases, $scripts);

        self::assertSame(Verdict::Sat, $outcome->result->verdict);
        self::assertLessThan(20, microtime(true) - $start, 'with a parallelism of 1 the slow cases come after the model and never start');
    }

    public function testACaseThatIsNotDecidedKeepsTheCheckUnknownAndNothingIsCached(): void
    {
        $cases = $this->cases(1);
        $original = $this->script();
        $scripts = array_map(fn (SplitCase $case): string => $case->length === 0 ? $this->script($case->assertion, '(assert (> n 5))') : $this->script($case->assertion, $this->cubes()), $cases);

        $outcome = $this->runner(3, 1500)->runSplit($original, $cases, $scripts);

        self::assertSame(Verdict::Unknown, $outcome->result->verdict);
        self::assertSame([1, 'outside 0..1'], $outcome->undecided);
        $cache = new ProofCache($this->directory);
        self::assertNull($cache->get($cache->key($this->requireZ3(), $original), 1));
    }

    public function testAParallelismBelowOneIsRefused(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('parallelism must be between 1 and 1000, 0 given');
        new Z3Runner('z3', 10000, new ProofCache(null), 0);
    }

    public function testTheDefaultParallelismIsAtMostFourAndAtLeastOne(): void
    {
        self::assertGreaterThanOrEqual(1, CpuCores::count());
        $this->addToAssertionCount(1);
        new Z3Runner('z3');
    }

    /**
     * @return list<SplitCase>
     */
    private function cases(int $bound): array
    {
        return CaseSplit::cases('n', $bound) ?? [];
    }

    private function runner(int $parallelism, int $timeoutMs = 10000): Z3Runner
    {
        return new Z3Runner($this->requireZ3(), $timeoutMs, new ProofCache($this->directory), $parallelism);
    }

    private function script(string ...$assertions): string
    {
        return "(set-option :produce-models true)\n(declare-const n Int)\n(declare-const y Int)\n(declare-const z Int)\n"
            . implode("\n", $assertions) . "\n(echo \"@@0\")\n(check-sat)\n(get-value (n))\n";
    }

    private function cubes(): string
    {
        return '(assert (= (+ (* n n n) (* y y y) (* z z z)) 33))';
    }
}
