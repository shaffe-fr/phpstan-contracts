<?php

declare(strict_types=1);

namespace PhpstanContracts\Solver;

use InvalidArgumentException;
use Symfony\Component\Process\Exception\ProcessTimedOutException;
use Symfony\Component\Process\ExecutableFinder;
use Symfony\Component\Process\Process;

/**
 * What every Z3 runner shares: the limits, the budget, the proof cache, and the split,
 * whose cases always run in disposable parallel processes.
 *
 * The two concrete runners differ only in how the plain `run()` scripts reach Z3:
 * {@see Z3Runner} starts a process for each one, {@see PersistentZ3Runner} asks a single
 * process that stays alive, a `(reset)` between the scripts.
 */
abstract class AbstractZ3Runner implements CheckRunner
{
    protected const MAXIMUM_TIMEOUT_MS = 86_400_000;

    protected const MAXIMUM_CHECKS = 100_000;

    protected const MAXIMUM_PARALLELISM = 1000;

    /** Z3 processes per case of a split, each with its own random seed: the time Z3 takes has a heavy tail. */
    public const SEEDS = 2;

    /** Z3 options of a case of a split. The first only changes how Z3 searches a non-linear problem, never what an answer means. */
    protected const SPLIT_OPTIONS = ['smt.arith.nl.horner=false'];

    protected Z3OutputParser $parser;

    /** @var int<1, 86400000> */
    protected readonly int $timeoutMs;

    /** @var int<1, 1000> */
    protected readonly int $parallelism;

    /**
     * @param int|null $parallelism the most Z3 processes that run at once for the cases of a split, `min(4, CPU cores)` when null
     */
    public function __construct(
        protected readonly string $binary = 'z3',
        int $timeoutMs = 10000,
        protected readonly ProofCache $cache = new ProofCache(null),
        ?int $parallelism = null,
        protected readonly Clock $clock = new SystemClock(),
        protected readonly RunBudget $runBudget = new RunBudget(),
    ) {
        if ($timeoutMs < 1 || $timeoutMs > self::MAXIMUM_TIMEOUT_MS) {
            throw new InvalidArgumentException(sprintf('The solver timeout must be between 1 and %d milliseconds, %d given.', self::MAXIMUM_TIMEOUT_MS, $timeoutMs));
        }
        $parallelism ??= min(4, CpuCores::count());
        if ($parallelism < 1 || $parallelism > self::MAXIMUM_PARALLELISM) {
            throw new InvalidArgumentException(sprintf('The parallelism must be between 1 and %d, %d given.', self::MAXIMUM_PARALLELISM, $parallelism));
        }

        $this->timeoutMs = $timeoutMs;
        $this->parallelism = $parallelism;
        $this->parser = new Z3OutputParser();
    }

    public function runSplit(string $original, array $cases, array $scripts, ?int $deadlineMs = null): SplitOutcome
    {
        if ($cases === [] || count($cases) !== count($scripts)) {
            throw new InvalidArgumentException('A split needs one script per case.');
        }

        $binary = $this->locate();
        $this->assertRunBudget();
        $started = $this->clock->nowMs();
        try {
            [$answers, $cut] = $this->race($binary, $scripts, $deadlineMs);
        } finally {
            $this->runBudget->spend($this->clock->nowMs() - $started);
        }
        $outcome = SplitOutcome::combine($cases, $answers, $cut);

        if ($outcome->result->verdict !== Verdict::Unknown && $this->cache->isEnabled()) {
            $this->cache->put($this->cache->key($binary, $original), [$outcome->result]);
        }

        return $outcome;
    }

    /**
     * @param list<string> $scripts
     * @return array{array<int, SolverResult>, bool} the decided cases by position, and whether the deadline left a case without an answer it could have had
     */
    private function race(string $binary, array $scripts, ?int $deadlineMs): array
    {
        // Every case gets its first seed before any case gets its second, so a low parallelism
        // spends its processes on the cases rather than on a second try of the first one.
        $queue = [];
        for ($seed = 0; $seed < self::SEEDS; ++$seed) {
            foreach (array_keys($scripts) as $case) {
                $queue[] = [$case, $seed];
            }
        }

        /** @var array<int, SolverResult> $answers */
        $answers = [];
        /** @var array<int, true> $cutCases the cases that the deadline kept from a full try */
        $cutCases = [];
        /** @var array<int, array{Process, int, bool, bool}> $running the process, its case, whether its limit was cut short and whether its kill is the deadline */
        $running = [];
        try {
            while ($queue !== [] || $running !== []) {
                while ($queue !== [] && count($running) < $this->parallelism) {
                    [$case, $seed] = array_shift($queue);
                    if (isset($answers[$case])) {
                        continue;
                    }
                    $remaining = $this->remaining($deadlineMs);
                    if ($remaining !== null && $remaining <= 0) {
                        $cutCases[$case] = true;
                        continue;
                    }
                    $limit = $remaining === null ? $this->timeoutMs : min($this->timeoutMs, $remaining);
                    $full = TimeLimits::processSeconds($limit, 1) * 1000;
                    $killMs = $remaining === null ? $full : TimeLimits::killMs($limit, 1, min($remaining, self::MAXIMUM_TIMEOUT_MS));
                    $process = new Process([
                        $binary, '-in', '-smt2', '-t:' . $limit, ...self::SPLIT_OPTIONS,
                        'smt.random_seed=' . $seed, 'sat.random_seed=' . $seed,
                    ]);
                    $process->setInput($scripts[$case]);
                    $process->setTimeout($killMs / 1000);
                    $process->start();
                    $running[] = [$process, $case, $limit < $this->timeoutMs, $killMs < $full];
                }

                foreach ($running as $position => [$process, $case, $capped, $cutByBudget]) {
                    if (isset($answers[$case])) {
                        $process->stop(0);
                        unset($running[$position]);
                        continue;
                    }
                    try {
                        $process->checkTimeout();
                    } catch (ProcessTimedOutException) {
                        unset($running[$position]);
                        if ($cutByBudget) {
                            $cutCases[$case] = true;
                        }
                        continue;
                    }
                    if ($process->isRunning()) {
                        continue;
                    }
                    unset($running[$position]);
                    try {
                        $result = $this->parser->parse($process->getOutput(), 1)[0];
                    } catch (SolverFailure) {
                        continue;
                    }
                    if ($result->verdict === Verdict::Unknown) {
                        if ($capped) {
                            $cutCases[$case] = true;
                        }
                        continue;
                    }
                    $answers[$case] = $result;
                    if ($result->verdict === Verdict::Sat) {
                        return [$answers, false];
                    }
                }

                if ($running !== []) {
                    usleep(5000);
                }
            }
        } finally {
            foreach ($running as [$process]) {
                $process->stop(0);
            }
        }

        return [$answers, array_diff_key($cutCases, $answers) !== []];
    }

    protected function locate(): string
    {
        if (is_file($this->binary)) {
            return $this->binary;
        }

        if ($this->binary === 'z3' && ($installed = Z3Installer::installedBinary()) !== null) {
            return $installed;
        }

        return (new ExecutableFinder())->find($this->binary)
            ?? throw new SolverFailure(sprintf(
                'Z3 executable "%s" not found. Run vendor/bin/phpstan-contracts-install-z3, install Z3 yourself, or set parameters.contracts.z3Binary.',
                $this->binary,
            ));
    }

    public function cacheEnabled(): bool
    {
        return $this->cache->isEnabled();
    }

    public function isRemembered(string $script, int $methodBudgetMs): bool
    {
        return $this->cache->isEnabled()
            && $this->cache->isUndecided($this->cache->undecidedKey($this->locate(), $script, $this->timeoutMs, $methodBudgetMs));
    }

    public function remember(string $script, int $methodBudgetMs): void
    {
        if ($this->cache->isEnabled()) {
            $this->cache->markUndecided($this->cache->undecidedKey($this->locate(), $script, $this->timeoutMs, $methodBudgetMs));
        }
    }

    /**
     * @param list<int> $skipped
     * @return list<SolverResult> every check is `unknown`: remembered for the skipped ones, cut by the budget for the others
     */
    protected function unanswered(int $checkCount, array $skipped): array
    {
        $results = [];
        for ($index = 0; $index < $checkCount; ++$index) {
            $results[] = new SolverResult(Verdict::Unknown, [], in_array($index, $skipped, true) ? UnknownReason::Remembered : UnknownReason::Budget);
        }

        return $results;
    }

    protected function remaining(?int $deadlineMs): ?int
    {
        return $deadlineMs === null ? null : $deadlineMs - $this->clock->nowMs();
    }

    protected function assertRunBudget(): void
    {
        if ($this->runBudget->isExhausted()) {
            throw new RunBudgetExhausted($this->runBudget->spentMs(), $this->runBudget->limitMs);
        }
    }
}
