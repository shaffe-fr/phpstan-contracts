<?php

declare(strict_types=1);

namespace PhpstanContracts\Solver;

/**
 * One case of a split of a check by the length of a list: the assertion that restricts the length.
 */
final readonly class SplitCase
{
    public function __construct(
        /** The length the case fixes, or null for the case of every length outside the bound. */
        public ?int $length,
        /** The `assert` command, to place in the script of the check. */
        public string $assertion,
        /** How a message names the case when Z3 did not decide it. */
        public string $description,
    ) {
    }
}
