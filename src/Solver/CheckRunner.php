<?php

declare(strict_types=1);

namespace PhpstanContracts\Solver;

/**
 * What {@see MethodSolver} needs from the solver: {@see Z3Runner} runs Z3, and a test double counts the
 * questions and moves a clock.
 */
interface CheckRunner
{
    /**
     * @param int|null $deadlineMs the reading of the clock after which no answer is waited for, null for no limit
     * @param list<int> $skipped the checks that the script does not hold
     * @return list<SolverResult> one result per check, in script order; a check that was not answered is `unknown`
     * @throws SolverFailure
     */
    public function run(string $script, int $checkCount, ?int $deadlineMs = null, array $skipped = []): array;

    /**
     * @param list<SplitCase> $cases
     * @param list<string> $scripts
     * @throws SolverFailure
     */
    public function runSplit(string $original, array $cases, array $scripts, ?int $deadlineMs = null): SplitOutcome;

    /**
     * Whether answers are kept on disk, which is what remembering an undecided check needs.
     */
    public function cacheEnabled(): bool;

    /**
     * Whether an earlier run tried this check in full, with the limits of this one, and left it undecided.
     */
    public function isRemembered(string $script, int $methodBudgetMs): bool;

    public function remember(string $script, int $methodBudgetMs): void;
}
