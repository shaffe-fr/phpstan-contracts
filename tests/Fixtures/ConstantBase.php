<?php

declare(strict_types=1);

namespace PhpstanContracts\Tests\Fixtures;

abstract class ConstantBase
{
    public const int LIMIT = 500;

    protected const int GUARDED = 4;

    private const int HIDDEN = 3;
}
