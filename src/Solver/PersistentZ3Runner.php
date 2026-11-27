<?php

declare(strict_types=1);

namespace PhpstanContracts\Solver;

use InvalidArgumentException;

/**
 * The persistent runner: one Z3 process per worker, alive across the scripts.
 *
 * Each script is asked of the same process: a `(reset)` wipes the previous declarations and
 * assertions, the script's per-check limit goes back as `(set-option :timeout …)`, the script is
 * written to a file that the process reads with `(include …)`, and the answers are read from a
 * file at a growing offset until an end marker. Files on both sides on purpose: on Windows a
 * pipe read can hang (PHP bug #51800), which is why Symfony reads even the disposable process's
 * output through files, and a pipe cannot be made non-blocking, so a large write would block.
 *
 * `(reset)` does not undo a global option such as `combined_solver.solver2_timeout`: after a
 * script that sets one, the next script gets a fresh process.
 *
 * The answers it returns are the same values a disposable run gives: only `sat` and `unsat`
 * are proofs, an `unknown` is never read as either, and a run cut by the budget leaves its
 * unanswered checks `unknown` as the budget's, exactly as {@see Z3Runner} does.
 */
final class PersistentZ3Runner extends AbstractZ3Runner
{
    /** The marker that ends one script's answers in the output. */
    private const END_MARKER = '@@END';

    /**
     * The output file is only ever appended to: once it is beyond this size, the next script starts
     * a fresh process with a fresh file. One script whose own output is beyond it is a solver failure.
     */
    private const MAX_OUTPUT_BYTES = 64 * 1024 * 1024;

    /** @var resource|null */
    private $proc = null;

    /** @var resource|null the stdin pipe, which only ever carries a few bytes per script */
    private $stdin = null;

    /** @var resource|null */
    private $outHandle = null;

    /** @var resource|null */
    private $errHandle = null;

    private int $outOffset = 0;

    private int $errOffset = 0;

    private ?string $outFile = null;

    private ?string $errFile = null;

    /** The file that holds the script being asked, which the process reads with `(include …)`. */
    private ?string $scriptFile = null;

    /** Whether the last script set a global option, which `(reset)` does not undo. */
    private bool $optionsChanged = false;

    private bool $shutdownHookRegistered = false;

    /**
     * Runs a script on the process that answers the worker's scripts, and answers every check
     * that Z3 answered with what it said, as {@see Z3Runner::run()} does: under a deadline the
     * limit of the checks is `min(timeoutMs, what is left)`, the process is killed when the
     * deadline comes, and every check without an answer is `unknown` with the reason
     * {@see UnknownReason::Budget}.
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

        $started = $this->clock->nowMs();
        try {
            [$output, $partial] = $this->askPersistent($binary, $script, $limit, $killMs);
        } finally {
            $this->runBudget->spend($this->clock->nowMs() - $started);
        }
        if ($partial && !$cutByBudget) {
            throw new SolverFailure('Z3 exceeded its overall time limit.');
        }

        try {
            $results = $this->parser->parse($output, $checkCount, $skipped, $partial, $limit < $this->timeoutMs);
        } catch (SolverFailure $failure) {
            // An output that cannot be read leaves the process in a state nobody checked.
            $this->destroyProcess();
            throw $failure;
        }
        if ($key !== null) {
            $this->cache->put($key, $results);
        }

        return $results;
    }

    /**
     * Writes the script to the script file, then `(reset)` + the per-check limit + an `include`
     * of that file + the end marker to the process, and reads the output at a growing offset
     * until the marker or the kill deadline.
     *
     * The script goes through a file rather than the stdin pipe because on Windows a pipe cannot
     * be made non-blocking: a write larger than the pipe buffer would block while Z3 is busy on a
     * check, and the kill deadline could not be enforced during that write. The few bytes that do
     * go through the pipe always fit, as Z3 has read everything sent before when it echoes the marker.
     *
     * @return array{string, bool} the output of the script, and whether the process was killed at the deadline
     */
    private function askPersistent(string $binary, string $script, int $limit, int $killMs): array
    {
        // The kill is timed by the wall clock, as the disposable process's timeout is: the
        // injected clock measures the budgets and may be a clock that never moves on its own.
        $killAt = hrtime(true) + $killMs * 1_000_000;

        // Z3 keeps the global options across a `(reset)`, so a script that sets one other than
        // `produce-models` (on by default) would change how the next scripts are searched.
        if ($this->optionsChanged || $this->outOffset > self::MAX_OUTPUT_BYTES) {
            $this->destroyProcess();
        }
        $this->ensureProcess($binary);
        if ($this->proc === null || $this->stdin === null || $this->outHandle === null || $this->scriptFile === null) {
            throw new SolverFailure('The persistent Z3 process is not running.');
        }
        /** @var resource $proc */
        $proc = $this->proc;
        /** @var resource $stdin */
        $stdin = $this->stdin;
        /** @var resource $outHandle */
        $outHandle = $this->outHandle;

        if (file_put_contents($this->scriptFile, $script) !== strlen($script)) {
            $this->destroyProcess();
            throw new SolverFailure('The script for the persistent Z3 process could not be written.');
        }
        $this->optionsChanged = preg_match('/\(\s*set-option\s+:(?!produce-models\s+true\s*\))/', $script) === 1;
        $includePath = str_replace('\\', '/', $this->scriptFile);

        $pending = '(reset)' . "\n"
            . '(set-option :timeout ' . $limit . ')' . "\n"
            . '(include "' . str_replace('"', '""', $includePath) . '")' . "\n"
            . '(echo "' . self::END_MARKER . '")' . "\n";
        $output = '';
        for (;;) {
            if ($pending !== '') {
                $n = @fwrite($stdin, $pending);
                if ($n !== false && $n > 0) {
                    $pending = substr($pending, $n);
                }
            }

            $chunk = stream_get_contents($outHandle, -1, $this->outOffset);
            if (is_string($chunk) && $chunk !== '') {
                $this->outOffset += strlen($chunk);
                $output .= $chunk;
            }

            // The marker is a line of its own: a string value of a model is printed quoted, its
            // line breaks escaped, so no model can end the answers early.
            if (preg_match('/^' . self::END_MARKER . '\r?\n/m', $output, $marker, PREG_OFFSET_CAPTURE) === 1) {
                $output = substr($output, 0, $marker[0][1]);

                // An error inside the included file names the file; without it, the output reads
                // as the disposable run's does.
                return [str_replace('(error "' . $includePath . ': ', '(error "', $output), false];
            }

            if (!proc_get_status($proc)['running']) {
                $rest = stream_get_contents($outHandle, -1, $this->outOffset);
                if (is_string($rest) && $rest !== '') {
                    $this->outOffset += strlen($rest);
                    $output .= $rest;
                }
                $detail = $this->errExcerpt();
                $this->destroyProcess();
                throw new SolverFailure(
                    'The persistent Z3 process ended before finishing the script' . ($detail !== '' ? ': ' . $detail : '.'),
                );
            }

            if (strlen($output) > self::MAX_OUTPUT_BYTES) {
                $this->destroyProcess();
                throw new SolverFailure('The output of one script to the persistent Z3 process exceeded its size limit.');
            }

            if (hrtime(true) >= $killAt) {
                $this->destroyProcess();
                return [str_replace('(error "' . $includePath . ': ', '(error "', $output), true];
            }

            usleep(1000);
        }
    }

    /**
     * Starts the process of the worker on first use: Z3 in interactive mode, its stdout to a
     * file read at a growing offset, its stdin a pipe that only carries the few commands that
     * include each script.
     */
    private function ensureProcess(string $binary): void
    {
        if ($this->proc !== null && proc_get_status($this->proc)['running']) {
            return;
        }
        $this->destroyProcess();

        $outFile = tempnam(sys_get_temp_dir(), 'contracts-z3-out-');
        $errFile = tempnam(sys_get_temp_dir(), 'contracts-z3-err-');
        $scriptFile = tempnam(sys_get_temp_dir(), 'contracts-z3-in-');
        if ($outFile === false || $errFile === false || $scriptFile === false) {
            foreach ([$outFile, $errFile, $scriptFile] as $file) {
                if ($file !== false) {
                    @unlink($file);
                }
            }
            throw new SolverFailure('The files of the persistent Z3 process could not be created.');
        }
        $proc = proc_open(
            [$binary, '-in', '-smt2'],
            [
                0 => ['pipe', 'r'],
                1 => ['file', $outFile, 'w'],
                2 => ['file', $errFile, 'w'],
            ],
            $pipes,
        );
        if (!is_resource($proc)) {
            @unlink($outFile);
            @unlink($errFile);
            @unlink($scriptFile);
            throw new SolverFailure('The persistent Z3 process could not be started.');
        }
        // Without effect on Windows, where a pipe always blocks: see askPersistent().
        stream_set_blocking($pipes[0], false);

        $outHandle = fopen($outFile, 'r');
        $errHandle = fopen($errFile, 'r');
        if (!is_resource($outHandle) || !is_resource($errHandle)) {
            if (is_resource($outHandle)) {
                fclose($outHandle);
            }
            if (is_resource($errHandle)) {
                fclose($errHandle);
            }
            fclose($pipes[0]);
            proc_terminate($proc);
            proc_close($proc);
            @unlink($outFile);
            @unlink($errFile);
            @unlink($scriptFile);
            throw new SolverFailure('The persistent Z3 output could not be opened.');
        }

        $this->proc = $proc;
        $this->stdin = $pipes[0];
        $this->outHandle = $outHandle;
        $this->errHandle = $errHandle;
        $this->outFile = $outFile;
        $this->errFile = $errFile;
        $this->scriptFile = $scriptFile;
        $this->outOffset = 0;
        $this->errOffset = 0;
        $this->optionsChanged = false;

        // The process must die with the worker: PHP does not kill its children on exit, and a
        // Z3 left behind would only outlive its answers.
        if (!$this->shutdownHookRegistered) {
            $this->shutdownHookRegistered = true;
            register_shutdown_function(function (): void {
                $this->destroyProcess();
            });
        }
    }

    /**
     * Terminates the process and closes its files, so the next use starts fresh.
     */
    private function destroyProcess(): void
    {
        if (is_resource($this->stdin)) {
            fclose($this->stdin);
        }
        $this->stdin = null;
        if ($this->proc !== null) {
            if (proc_get_status($this->proc)['running']) {
                proc_terminate($this->proc);
            }
            proc_close($this->proc);
            $this->proc = null;
        }
        if (is_resource($this->outHandle)) {
            fclose($this->outHandle);
        }
        $this->outHandle = null;
        if (is_resource($this->errHandle)) {
            fclose($this->errHandle);
        }
        $this->errHandle = null;
        foreach ([$this->outFile, $this->errFile, $this->scriptFile] as $file) {
            if ($file !== null) {
                @unlink($file);
            }
        }
        $this->outFile = null;
        $this->errFile = null;
        $this->scriptFile = null;
        $this->outOffset = 0;
        $this->errOffset = 0;
        $this->optionsChanged = false;
    }

    private function errExcerpt(): string
    {
        if (!is_resource($this->errHandle)) {
            return '';
        }
        $rest = stream_get_contents($this->errHandle, -1, $this->errOffset);
        if (!is_string($rest) || $rest === '') {
            return '';
        }
        $this->errOffset += strlen($rest);

        return substr(trim($rest), 0, 400);
    }
}
