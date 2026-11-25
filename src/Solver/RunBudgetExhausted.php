<?php

declare(strict_types=1);

namespace PhpstanContracts\Solver;

/**
 * The run budget was spent before this question was asked, so Z3 was not started. It is a
 * {@see SolverFailure} so that no caller can read it as an answer, and it is caught before the
 * genuine failures where it is reported on its own.
 */
final class RunBudgetExhausted extends SolverFailure
{
    public function __construct(
        public readonly int $spentMs,
        public readonly int $limitMs,
    ) {
        parent::__construct(sprintf('The solver budget of this run is exhausted: %d ms spent of %d ms.', $spentMs, $limitMs));
    }
}
