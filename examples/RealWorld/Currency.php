<?php

declare(strict_types=1);

namespace PhpstanContracts\Examples\RealWorld;

enum Currency: string
{
    case Eur = 'EUR';
    case Usd = 'USD';
    case Gbp = 'GBP';
}
