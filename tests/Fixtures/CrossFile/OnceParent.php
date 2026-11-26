<?php

declare(strict_types=1);

namespace PhpstanContracts\Tests\Fixtures\CrossFile;

abstract class OnceParent
{
    final protected function value(): int
    {
        return 1;
    }
}
