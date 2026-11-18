<?php

declare(strict_types=1);

namespace PhpstanContracts\Translate;

use PhpstanContracts\Smt\Sort;

/**
 * An SMT constant that has a meaning for the user (parameter, result or return marker),
 * kept to turn a Z3 model back into PHP terms.
 */
final readonly class NamedSymbol
{
    public function __construct(
        public string $smtName,
        public string $phpName,
        public Sort $sort,
        public int $line = 0,
    ) {
    }
}
