<?php

declare(strict_types=1);

namespace PhpstanContracts\Solver;

/**
 * Builds the runner behind `parameters.contracts.*`: the disposable {@see Z3Runner} by default, the
 * single-process {@see PersistentZ3Runner} when `contracts.persistentSolver` is on.
 *
 * It is a service, not a static call, so that the runner it builds gets the same shared services
 * the container provides — the {@see ProofCache} with the configured directory, the {@see Clock},
 * and the {@see RunBudget} with the configured limit. Building the runner with fresh instances of
 * those instead would silently disable the proof cache and the run budget.
 */
final class Z3RunnerFactory
{
    public function __construct(
        private readonly ProofCache $cache,
        private readonly Clock $clock,
        private readonly RunBudget $runBudget,
    ) {
    }

    /**
     * @param int|null $parallelism
     */
    public function create(string $binary, int $timeoutMs, ?int $parallelism, bool $persistent): CheckRunner
    {
        return $persistent
            ? new PersistentZ3Runner($binary, $timeoutMs, $this->cache, $parallelism, $this->clock, $this->runBudget)
            : new Z3Runner($binary, $timeoutMs, $this->cache, $parallelism, $this->clock, $this->runBudget);
    }
}
