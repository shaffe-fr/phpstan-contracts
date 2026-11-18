<?php

declare(strict_types=1);

namespace PhpstanContracts\Examples;

/**
 * Backing values are the loyalty bonus in percent.
 */
enum Tier: int
{
    case Standard = 0;
    case Silver = 5;
    case Gold = 15;
}
