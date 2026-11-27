<?php

declare(strict_types=1);

namespace PhpstanContracts\Solver;

use InvalidArgumentException;
use Symfony\Component\Process\Exception\ProcessTimedOutException;
use Symfony\Component\Process\Process;

/**
 * The disposable runner: one Z3 process per script, as the package has always done it.
 *
 * On Windows this is the expensive path — Symfony wraps every call in
 * `cmd.exe /C ( … ) 1>"temp file"` and polls the temp files, so each call starts two
 * processes and spends on the order of 200 ms of that before Z3 even starts thinking.
 * {@see PersistentZ3Runner} is the alternative when `contracts.persistentSolver` is on.
 */
final class Z3Runner extends AbstractZ3Runner
{
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

        $results = $this->parser->parse($process->getOutput(), $checkCount, $skipped, $partial, $limit < $this->timeoutMs);
        if ($key !== null) {
            $this->cache->put($key, $results);
        }

        return $results;
    }
}
