<?php

declare(strict_types=1);

namespace PhpstanContracts\Tests\Fixtures\CrossFile;

// Declared once, but its parent TwiceMiddle is declared twice.
final readonly class TwiceLeaf extends TwiceMiddle
{
}
