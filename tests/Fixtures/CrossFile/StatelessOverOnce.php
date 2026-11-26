<?php

declare(strict_types=1);

namespace PhpstanContracts\Tests\Fixtures\CrossFile;

use PhpstanContracts\Attribute\Ensures;

/**
 * A service without state whose `$this->value()` is inherited from a parent of another file.
 */
final class StatelessOverOnce extends OnceParent
{
    #[Ensures('$result === 1')]
    public function viaThis(): int
    {
        return $this->value();
    }
}
