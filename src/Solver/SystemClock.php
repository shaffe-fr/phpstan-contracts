<?php

declare(strict_types=1);

namespace PhpstanContracts\Solver;

final class SystemClock implements Clock
{
    public function nowMs(): int
    {
        return intdiv(hrtime(true), 1_000_000);
    }
}
