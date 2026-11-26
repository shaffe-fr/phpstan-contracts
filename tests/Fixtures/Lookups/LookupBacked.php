<?php

declare(strict_types=1);

namespace PhpstanContracts\Tests\Fixtures\Lookups;

enum LookupBacked: int
{
    case One = LookupDeepCase::VALUE;
}
