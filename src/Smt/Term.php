<?php

declare(strict_types=1);

namespace PhpstanContracts\Smt;

final readonly class Term
{
    public function __construct(
        public string $smt,
        public Sort $sort,
        public bool $atomic = false,
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
