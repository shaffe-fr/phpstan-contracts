<?php

declare(strict_types=1);

namespace PhpstanContracts\Tests\Support;

use Closure;
use LogicException;
use PhpstanContracts\Solver\CheckRunner;
use PhpstanContracts\Solver\SolverResult;
use PhpstanContracts\Solver\SplitCase;
use PhpstanContracts\Solver\SplitOutcome;
use PhpstanContracts\Solver\UnknownReason;
use PhpstanContracts\Solver\Verdict;

/**
 * A solver that answers what the test scripted and counts every question: a question that is not
 * counted never reached Z3. What it is told to remember stays remembered across runs.
 */
final class FakeCheckRunner implements CheckRunner
{
    /** @var Closure(string, int, ?int, list<int>): list<SolverResult>|null the answer to a script of the main run, or to a retry (one check) */
    public ?Closure $onRun = null;

    /** @var Closure(string, list<SplitCase>, list<string>, ?int): SplitOutcome|null */
    public ?Closure $onSplit = null;

    /** @var list<array{string, int, ?int, list<int>}> */
    public array $runs = [];

    public int $splits = 0;

    /** @var array<string, int> the scripts that are remembered, and the budget they were remembered under */
    public array $remembered = [];

    public int $queries = 0;

    public bool $enabled = true;

    public function run(string $script, int $checkCount, ?int $deadlineMs = null, array $skipped = []): array
    {
        $this->runs[] = [$script, $checkCount, $deadlineMs, $skipped];

        $results = ($this->onRun ?? throw new LogicException('Z3 was not expected to run.'))($script, $checkCount, $deadlineMs, $skipped);
        return array_map(
            static fn (SolverResult $result, int $check): SolverResult => in_array($check, $skipped, true) ? new SolverResult(Verdict::Unknown, [], UnknownReason::Remembered) : $result,
            $results,
            array_keys($results),
        );
    }

    public function runSplit(string $original, array $cases, array $scripts, ?int $deadlineMs = null): SplitOutcome
    {
        ++$this->splits;

        return ($this->onSplit ?? throw new LogicException('The split was not expected to run.'))($original, $cases, $scripts, $deadlineMs);
    }

    public function cacheEnabled(): bool
    {
        return $this->enabled;
    }

    public function isRemembered(string $script, int $methodBudgetMs): bool
    {
        ++$this->queries;

        return ($this->remembered[$script] ?? null) === $methodBudgetMs;
    }

    public function remember(string $script, int $methodBudgetMs): void
    {
        $this->remembered[$script] = $methodBudgetMs;
    }
}
