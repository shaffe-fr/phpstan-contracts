<?php

declare(strict_types=1);

namespace PhpstanContracts\Solver;

use InvalidArgumentException;
use PhpstanContracts\Smt\SExpr;
use Symfony\Component\Process\Exception\ProcessTimedOutException;
use Symfony\Component\Process\ExecutableFinder;
use Symfony\Component\Process\Process;

final class Z3Runner
{
    private const MAXIMUM_TIMEOUT_MS = 86_400_000;

    private const MAXIMUM_CHECKS = 100_000;

    /** @var int<1, 86400000> */
    private readonly int $timeoutMs;

    public function __construct(
        private readonly string $binary = 'z3',
        int $timeoutMs = 10000,
        private readonly ProofCache $cache = new ProofCache(null),
    ) {
        if ($timeoutMs < 1 || $timeoutMs > self::MAXIMUM_TIMEOUT_MS) {
            throw new InvalidArgumentException(sprintf('The solver timeout must be between 1 and %d milliseconds, %d given.', self::MAXIMUM_TIMEOUT_MS, $timeoutMs));
        }

        $this->timeoutMs = $timeoutMs;
    }

    /**
     * @return list<SolverResult> one result per check, in script order
     */
    public function run(string $script, int $checkCount): array
    {
        if ($checkCount < 1 || $checkCount > self::MAXIMUM_CHECKS) {
            throw new InvalidArgumentException(sprintf('A script holds between 1 and %d checks, %d given.', self::MAXIMUM_CHECKS, $checkCount));
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

        $process = new Process([$binary, '-in', '-smt2', '-t:' . $this->timeoutMs]);
        $process->setInput($script);
        $process->setTimeout(TimeLimits::processSeconds($this->timeoutMs, $checkCount));

        try {
            $process->run();
        } catch (ProcessTimedOutException) {
            throw new SolverFailure('Z3 exceeded its overall time limit.');
        }

        $results = $this->parse($process->getOutput(), $checkCount);

        if ($key !== null) {
            $this->cache->put($key, $results);
        }

        return $results;
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
     * @return list<SolverResult>
     */
    private function parse(string $output, int $checkCount): array
    {
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

        /** @var array<int, SolverResult> $results */
        $results = [];
        foreach ($blocks as $block) {
            $results[(int) $block[1]] = $this->parseBlock($block[2], $output);
        }

        $ordered = [];
        for ($index = 0; $index < $checkCount; ++$index) {
            $ordered[] = $results[$index]
                ?? throw new SolverFailure('Z3 produced no answer for check ' . $index . ': ' . $this->excerpt($output));
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
                if (is_array($pair) && count($pair) === 2 && is_string($pair[0])) {
                    /** @var string|list<mixed> $value */
                    $value = $pair[1];
                    $values[$pair[0]] = $value;
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
