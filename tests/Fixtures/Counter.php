<?php

declare(strict_types=1);

namespace PhpstanContracts\Tests\Fixtures;

use PhpstanContracts\Attribute\Ensures;
use PhpstanContracts\Attribute\Pure;
use PhpstanContracts\Attribute\Requires;

/**
 * A method without a body has nothing to verify, and the methods that implement it
 * do not inherit its contract.
 */
interface Counter
{
    #[Ensures('$result >= 0')]
    public function count(): int;

    public function reset(): void;
}

abstract class Shape
{
    #[Requires('$scale > 0')]
    #[Ensures('$result > 0')]
    abstract public function sides(int $scale): int;

    #[Ensures('$result === 0')]
    public function corners(): int
    {
        return 0;
    }
}

final class NegativeCounter implements Counter
{
    #[Pure]
    public function count(): int
    {
        return -1;
    }

    public function reset(): void
    {
    }
}
