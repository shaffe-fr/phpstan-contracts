<?php

declare(strict_types=1);

namespace PhpstanContracts\Translate;

use PhpstanContracts\Smt\Term;

/**
 * A safety condition that must hold wherever execution reaches `$pathCondition`.
 */
final readonly class Obligation
{
    public function __construct(
        public ObligationKind $kind,
        public int $line,
        public string $expression,
        public Term $pathCondition,
        public Term $goal,
    ) {
    }
}
