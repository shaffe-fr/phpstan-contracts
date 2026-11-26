<?php

declare(strict_types=1);

namespace PhpstanContracts\Tests\Fixtures\CrossFile;

/**
 * Exception factories declared in their own file.
 */
final class Refusals
{
    public static function negative(): NegativeRefusal
    {
        return new NegativeRefusal('Negative.');
    }

    public static function open(): OpenRefusal
    {
        return new OpenRefusal('Negative.');
    }
}
