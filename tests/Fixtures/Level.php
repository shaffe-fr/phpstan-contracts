<?php

declare(strict_types=1);

namespace PhpstanContracts\Tests\Fixtures;

enum Level: int
{
    case Low = 1;
    case Medium = 5;
    case High = 10;
}
