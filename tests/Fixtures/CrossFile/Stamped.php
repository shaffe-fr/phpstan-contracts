<?php

declare(strict_types=1);

namespace PhpstanContracts\Tests\Fixtures\CrossFile;

final readonly class Stamped extends ReceiptBase
{
    public function __construct(public int $totalCents)
    {
    }
}
