<?php

declare(strict_types=1);

namespace PhpstanContracts\Tests\Fixtures\Lookups;

enum LookupCode: string
{
    case A = Nowhere::X;
    case B = LookupRate::CODE;
}
