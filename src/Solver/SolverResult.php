<?php

declare(strict_types=1);

namespace PhpstanContracts\Solver;

final readonly class SolverResult
{
    /**
     * @param array<string, string|list<mixed>> $values SMT constant name => raw S-expression value, only filled when satisfiable
     */
    public function __construct(
        public Verdict $verdict,
        public array $values = [],
    ) {
    }
}
