<?php

declare(strict_types=1);

namespace PhpstanContracts\Tests\Fixtures;

use PhpstanContracts\Attribute\Ensures;

/**
 * A parent without state: its method reaches the method of the final subclass through `$this`.
 */
abstract class StatelessBase
{
    final public function doubledPlusOne(int $amount): int
    {
        return $this->twice($amount) + 1;
    }

    abstract protected function twice(int $amount): int;
}

/**
 * The parent is in the same file, so its method can be translated, and its call to `$this->twice()`
 * reaches the method of this final class.
 */
final class StatelessChild extends StatelessBase
{
    /**
     * @param int<0, 1000> $amount
     */
    #[Ensures('$result === $amount * 4 + 1')]
    public function quadrupledPlusOne(int $amount): int
    {
        return $this->doubledPlusOne($amount * 2);
    }

    protected function twice(int $amount): int
    {
        return $amount * 2;
    }
}
