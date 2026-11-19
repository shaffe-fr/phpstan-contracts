<?php

declare(strict_types=1);

namespace PhpstanContracts\Tests\Fixtures;

use PhpstanContracts\Attribute\Pure;

/**
 * @final
 *
 * Only the `final` keyword stops a subclass at runtime, so this tag does not make `static::` safe.
 */
class AnnotatedFinal
{
    public const int LIMIT = 100;

    #[Pure]
    public static function limit(): int
    {
        return static::LIMIT;
    }
}
