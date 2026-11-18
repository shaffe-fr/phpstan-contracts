<?php

declare(strict_types=1);

namespace PhpstanContracts\Translate;

use PhpstanContracts\Smt\Term;

final readonly class EnsuresCheck
{
    public function __construct(
        public string $source,
        public int $line,
        public Term $term,
    ) {
    }
}
