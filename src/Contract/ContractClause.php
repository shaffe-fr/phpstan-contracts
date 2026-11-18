<?php

declare(strict_types=1);

namespace PhpstanContracts\Contract;

use PhpParser\Node\Expr;

final readonly class ContractClause
{
    public function __construct(
        public string $source,
        public Expr $expression,
        public int $line,
    ) {
    }
}
