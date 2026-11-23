<?php

declare(strict_types=1);

namespace PhpstanContracts\Tests\Fixtures;

use PhpstanContracts\Attribute\Ensures;
use PhpstanContracts\Attribute\Pure;

/**
 * PHP 8.4 syntax: only parsed by PHPStan, never loaded by the test.
 */
final class HookContracts
{
    public int $next {
        #[Pure]
        get => PHP_INT_MAX + 1;
    }

    public int $checked {
        #[Ensures('$result >= 0')]
        get => -1;
    }

    public int $plain {
        get => 1;
    }
}
