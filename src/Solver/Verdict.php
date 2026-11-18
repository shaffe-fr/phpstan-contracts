<?php

declare(strict_types=1);

namespace PhpstanContracts\Solver;

enum Verdict: string
{
    case Sat = 'sat';
    case Unsat = 'unsat';
    case Unknown = 'unknown';
}
