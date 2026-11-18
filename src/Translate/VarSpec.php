<?php

declare(strict_types=1);

namespace PhpstanContracts\Translate;

use PhpstanContracts\Smt\Sort;

/**
 * What PHPStan knows about a value's type, in the shape the encoder needs:
 * the SMT sort plus the domain restrictions the sort alone cannot express.
 */
final readonly class VarSpec
{
    /**
     * @param array<string, VarSpec> $fields record fields by name
     */
    public function __construct(
        public Sort $sort,
        public ?int $min = null,
        public ?int $max = null,
        public ?VarSpec $inner = null,
        public array $fields = [],
        public bool $nonEmpty = false,
        public string $description = '',
    ) {
    }
}
