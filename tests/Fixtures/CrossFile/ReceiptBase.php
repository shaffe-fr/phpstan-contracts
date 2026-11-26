<?php

declare(strict_types=1);

namespace PhpstanContracts\Tests\Fixtures\CrossFile;

/**
 * The parent of Stamped, in a third file: its destructor runs for every Stamped.
 */
abstract readonly class ReceiptBase
{
    private function __destruct()
    {
    }
}
