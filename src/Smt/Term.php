<?php

declare(strict_types=1);

namespace PhpstanContracts\Smt;

final readonly class Term
{
    public function __construct(
        public string $smt,
        public Sort $sort,
        public bool $atomic = false,
        /**
         * For an array literal, the terms of its fields in the order of the sort, so that
         * a literal holding `null` (which no datatype can hold) can still be read and be
         * rebuilt for the type it is given.
         *
         * @var list<Term>|null
         */
        public ?array $components = null,
    ) {
    }

    public function isTrue(): bool
    {
        return $this->smt === 'true';
    }

    public function isFalse(): bool
    {
        return $this->smt === 'false';
    }
}
