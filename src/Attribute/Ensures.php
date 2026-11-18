<?php

declare(strict_types=1);

namespace PhpstanContracts\Attribute;

use Attribute;

/**
 * Postcondition over the return value (`$result`) and the entry values of the
 * parameters, even when the body reassigns them.
 */
#[Attribute(Attribute::TARGET_METHOD | Attribute::IS_REPEATABLE)]
final readonly class Ensures
{
    public function __construct(public string $expression)
    {
    }
}
