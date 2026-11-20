<?php

declare(strict_types=1);

namespace PhpstanContracts\Contract;

final readonly class ThrowsClause
{
    public function __construct(
        public string $class,
        public ?ContractClause $when,
        public int $line,
    ) {
    }
}
