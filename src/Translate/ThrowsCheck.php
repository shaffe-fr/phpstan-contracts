<?php

declare(strict_types=1);

namespace PhpstanContracts\Translate;

use PhpstanContracts\Smt\Term;

/**
 * A `#[Throws(..., when: ...)]` is violated when the method returns although its condition holds.
 */
final readonly class ThrowsCheck
{
    public function __construct(
        public string $class,
        public string $when,
        public int $line,
        public Term $violation,
    ) {
    }
}
