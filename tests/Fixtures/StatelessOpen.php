<?php

declare(strict_types=1);

namespace PhpstanContracts\Tests\Fixtures;

use PhpstanContracts\Attribute\Ensures;

/**
 * Not final: a subclass can replace a public method, so only a final or a private method is called.
 */
class StatelessOpen
{
    /**
     * @param int<0, 1000> $amount
     */
    #[Ensures('$result === $amount + 1')]
    public function callsAPrivateMethod(int $amount): int
    {
        return $this->next($amount);
    }

    /**
     * @param int<0, 1000> $amount
     */
    #[Ensures('$result === $amount + 2')]
    public function callsAFinalMethod(int $amount): int
    {
        return $this->afterNext($amount);
    }

    /**
     * @param int<0, 1000> $amount
     */
    #[Ensures('$result === $amount + 3')]
    public function callsAMethodThatCanBeOverridden(int $amount): int
    {
        return $this->overridable($amount);
    }

    private function next(int $amount): int
    {
        return $amount + 1;
    }

    final protected function afterNext(int $amount): int
    {
        return $amount + 2;
    }

    protected function overridable(int $amount): int
    {
        return $amount + 3;
    }
}
