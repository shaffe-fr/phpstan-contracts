<?php

declare(strict_types=1);

namespace PhpstanContracts\Contract;

final readonly class MethodContract
{
    /**
     * @param list<ContractClause> $requires
     * @param list<ContractClause> $ensures
     * @param list<ThrowsClause> $throws
     */
    public function __construct(
        public array $requires,
        public array $ensures,
        public bool $pure,
        public array $throws = [],
    ) {
    }

    public function isEmpty(): bool
    {
        return $this->requires === [] && $this->ensures === [] && $this->throws === [] && !$this->pure;
    }
}
