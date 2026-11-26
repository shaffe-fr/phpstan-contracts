<?php

declare(strict_types=1);

namespace PhpstanContracts\Tests\Fixtures\CrossFile;

use PhpstanContracts\Attribute\Ensures;

/**
 * A service without state whose `$this->value()` is inherited from a parent of another file.
 */
final class StatelessOverTwice extends TwiceParent
{
    // PHP returns 2: the body that PHPStan reflects is not the one that runs.
    #[Ensures('$result === 1')]
    public function viaThis(): int
    {
        return $this->value();
    }
}
