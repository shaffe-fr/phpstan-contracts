<?php

declare(strict_types=1);

namespace PhpstanContracts\Tests\Fixtures;

final readonly class Recursive
{
    public function __construct(public ?Recursive $next)
    {
    }
}
