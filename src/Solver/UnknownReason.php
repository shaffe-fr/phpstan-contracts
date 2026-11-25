<?php

declare(strict_types=1);

namespace PhpstanContracts\Solver;

/**
 * Why a check is `unknown` when Z3 did not simply give up at its time limit. No reason is an answer.
 */
enum UnknownReason: string
{
    /** The budget of the method ran out before the check was tried in full, or cut it short. */
    case Budget = 'budget';

    /** The budget of the run was spent before the check was tried in full. */
    case RunBudget = 'run-budget';

    /** An earlier run tried the check in full with these limits and left it undecided. */
    case Remembered = 'remembered';
}
