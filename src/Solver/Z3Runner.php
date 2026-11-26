<?php

declare(strict_types=1);

namespace PhpstanContracts\Solver;

use InvalidArgumentException;
use PhpstanContracts\Smt\SExpr;
use Symfony\Component\Process\Exception\ProcessTimedOutException;
use Symfony\Component\Process\ExecutableFinder;
use Symfony\Component\Process\Process;

final class Z3Runner implements CheckRunner
{
    private const MAXIMUM_TIMEOUT_MS = 86_400_000;

    private const MAXIMUM_CHECKS = 100_000;

    private const MAXIMUM_PARALLELISM = 1000;

    /** Z3 processes per case of a split, each with its own random seed: the time Z3 takes has a heavy tail. */
    public const SEEDS = 2;

    /** Z3 options of a case of a split. The first only changes how Z3 searches a non-linear problem, never what an answer means. */
    private const SPLIT_OPTIONS = ['smt.arith.nl.horner=false'];

    /** @var int<1, 86400000> */
    private readonly int $timeoutMs;

    /** @var int<1, 1000> */
    private readonly int $parallelism;

    /**
     * @param int|null $parallelism the most Z3 processes that run at once for the cases of a split, `min(4, CPU cores)` when null
     */
    public function __construct(
        private readonly string $binary = 'z3',
        int $timeoutMs = 10000,
        private readonly ProofCache $cache = new ProofCache(null),
        ?int $parallelism = null,
        private readonly Clock $clock = new SystemClock(),
        private readonly RunBudget $runBudget = new RunBudget(),
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
    }

    /**
     * Runs a script, and answers every check that Z3 answered with what it said.
     *
     * Under a deadline, Z3 is given `min(timeoutMs, what is left)` for each check and the process is
     * killed when the deadline comes ({@see TimeLimits::killMs()}). The answers that were printed
     * until then are kept, and every check without an answer is `unknown` with the reason
     * {@see UnknownReason::Budget}: a run cut short by the budget is not a solver failure, and it is
     * never read as `unsat`. Only a block that is certainly whole is read: the last one is dropped
     * unless it is a plain `unsat` or `unknown`, as a model that was cut off must not be read.
     * Without a deadline, a process that exceeds its limit is a solver failure.
     *
     * @param list<int> $skipped the checks that the script does not hold, which come back `unknown` as remembered
     * @return list<SolverResult> one result per check, in script order
     */
    public function run(string $script, int $checkCount, ?int $deadlineMs = null, array $skipped = []): array
    {
        if ($checkCount < 1 || $checkCount > self::MAXIMUM_CHECKS) {
            throw new InvalidArgumentException(sprintf('A script holds between 1 and %d checks, %d given.', self::MAXIMUM_CHECKS, $checkCount));
        }
        $asked = min($checkCount - count($skipped), self::MAXIMUM_CHECKS);
        if ($asked < 1) {
            return $this->unanswered($checkCount, $skipped);
        }

        $binary = $this->locate();
        $key = null;
        if ($this->cache->isEnabled()) {
            $key = $this->cache->key($binary, $script);
            $cached = $this->cache->get($key, $checkCount);
            if ($cached !== null) {
                return $cached;
            }
        }

        $this->assertRunBudget();
        $remaining = $this->remaining($deadlineMs);
        if ($remaining !== null && $remaining <= 0) {
            return $this->unanswered($checkCount, $skipped);
        }

        $limit = $remaining === null ? $this->timeoutMs : min($this->timeoutMs, $remaining);
        $full = TimeLimits::processSeconds($limit, $asked) * 1000;
        $killMs = $remaining === null ? $full : TimeLimits::killMs($limit, $asked, min($remaining, self::MAXIMUM_TIMEOUT_MS));
        $cutByBudget = $killMs < $full;

        $process = new Process([$binary, '-in', '-smt2', '-t:' . $limit]);
        $process->setInput($script);
        $process->setTimeout($killMs / 1000);
        $started = $this->clock->nowMs();
        $partial = false;
        try {
            $process->run();
        } catch (ProcessTimedOutException) {
            if (!$cutByBudget) {
                throw new SolverFailure('Z3 exceeded its overall time limit.');
            }
            $partial = true;
        } finally {
            $this->runBudget->spend($this->clock->nowMs() - $started);
        }

        $results = $this->parse($process->getOutput(), $checkCount, $skipped, $partial, $limit < $this->timeoutMs);
        if ($key !== null) {
            $this->cache->put($key, $results);
        }

        return $results;
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

    private function remaining(?int $deadlineMs): ?int
    {
        return $deadlineMs === null ? null : $deadlineMs - $this->clock->nowMs();
    }

    private function assertRunBudget(): void
    {
        if ($this->runBudget->isExhausted()) {
            throw new RunBudgetExhausted($this->runBudget->spentMs(), $this->runBudget->limitMs);
        }
    }

    /**
     * @param list<int> $skipped
     * @return list<SolverResult> every check is `unknown`: remembered for the skipped ones, cut by the budget for the others
     */
    private function unanswered(int $checkCount, array $skipped): array
    {
        $results = [];
        for ($index = 0; $index < $checkCount; ++$index) {
            $results[] = new SolverResult(Verdict::Unknown, [], in_array($index, $skipped, true) ? UnknownReason::Remembered : UnknownReason::Budget);
        }

        return $results;
    }

    /**
     * Asks again the one check of `$original`, once per case of a split, and combines the answers:
     * see {@see SplitOutcome}. The cases run in separate Z3 processes, at most `parallelism` at a
     * time, each case with {@see self::SEEDS} seeds. A case is answered by its first seed that
     * decides it, and the first model of any case ends the run: the processes still running are
     * killed. A final `sat` or `unsat` is stored in the proof cache under the key of `$original`.
     *
     * @param string $original the script of the check without the split, one check
     * @param list<SplitCase> $cases a partition of the lengths of a list
     * @param list<string> $scripts the script of each case, in the order of `$cases`
     * @param int|null $deadlineMs the reading of the clock after which no case is started and the running ones are killed; a case that the deadline stopped leaves the check `unknown` for the reason {@see UnknownReason::Budget}
     */
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
                        $result = $this->parse($process->getOutput(), 1)[0];
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
    private function locate(): string
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

    /**
     * @param list<int> $skipped the checks that are not in the script, `unknown` as remembered
     * @param bool $partial whether the process was killed by the budget, so that the output stops anywhere
     * @param bool $capped whether the limit of the checks was cut short by the budget, so that their `unknown` is the budget's
     * @return list<SolverResult>
     */
    private function parse(string $output, int $checkCount, array $skipped = [], bool $partial = false, bool $capped = false): array
    {
        if ($partial) {
            // The last line may be cut in two.
            $end = strrpos($output, "\n");
            $output = $end === false ? '' : substr($output, 0, $end + 1);
        }

        // A command that fails is skipped and the script goes on, so a dropped assertion
        // would leave no trace but a weaker problem. The only expected error is the
        // `get-value` that follows an `unsat` or `unknown` answer.
        preg_match_all('/^\(error\b.*$/m', $output, $errors);
        foreach ($errors[0] as $error) {
            if (preg_match('/^\(error "line \d+ column \d+: model is not available"\)\s*$/', $error) !== 1) {
                throw new SolverFailure('Z3 reported an error: ' . substr(trim($error), 0, 400));
            }
        }

        preg_match_all('/^@@(\d+)\R(.*?)(?=^@@\d+\R|\z)/ms', $output, $blocks, PREG_SET_ORDER);
        $last = array_key_last($blocks);
        /** @var array<int, SolverResult> $results */
        $results = [];
        foreach ($blocks as $position => $block) {
            if ($partial && $position === $last) {
                // Whatever follows the last marker may be unfinished: only an answer that carries no model is certain.
                try {
                    $result = $this->parseBlock($block[2], $output);
                } catch (SolverFailure) {
                    continue;
                }
                if ($result->verdict === Verdict::Sat) {
                    continue;
                }
                $results[(int) $block[1]] = $result;
                continue;
            }
            $results[(int) $block[1]] = $this->parseBlock($block[2], $output);
        }

        $ordered = [];
        for ($index = 0; $index < $checkCount; ++$index) {
            if (in_array($index, $skipped, true)) {
                $ordered[] = new SolverResult(Verdict::Unknown, [], UnknownReason::Remembered);
            } elseif (isset($results[$index])) {
                $result = $results[$index];
                $ordered[] = $capped && $result->verdict === Verdict::Unknown ? new SolverResult(Verdict::Unknown, [], UnknownReason::Budget) : $result;
            } elseif ($partial) {
                $ordered[] = new SolverResult(Verdict::Unknown, [], UnknownReason::Budget);
            } else {
                throw new SolverFailure('Z3 produced no answer for check ' . $index . ': ' . $this->excerpt($output));
            }
        }

        return $ordered;
    }
    private function parseBlock(string $block, string $fullOutput): SolverResult
    {
        if (preg_match('/^\s*(sat|unsat|unknown)\b/', $block, $match) !== 1) {
            throw new SolverFailure('Unexpected Z3 answer: ' . $this->excerpt($fullOutput));
        }

        $verdict = Verdict::from($match[1]);
        if ($verdict !== Verdict::Sat) {
            return new SolverResult($verdict);
        }

        $expressions = SExpr::parse(substr($block, strlen($match[0])));
        $values = [];
        $pairs = $expressions[0] ?? [];
        if (is_array($pairs)) {
            foreach ($pairs as $pair) {
                if (is_array($pair) && count($pair) === 2 && (is_string($pair[0]) || is_array($pair[0]))) {
                    /** @var string|list<mixed> $value */
                    $value = $pair[1];
                    // A probe term such as (select (List_Int_items l@1) 0) is keyed by its text.
                    $values[SExpr::render($pair[0])] = $value;
                }
            }
        }

        return new SolverResult($verdict, $values);
    }

    private function excerpt(string $output): string
    {
        return substr(trim($output), 0, 400);
    }
}
