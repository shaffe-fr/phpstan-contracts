<?php

declare(strict_types=1);

namespace PhpstanContracts\Attribute;

use Attribute;

/**
 * Precondition over the method parameters. The expression is a PHP expression
 * written in a string; it is parsed and translated to SMT-LIB, never executed.
 */
#[Attribute(Attribute::TARGET_METHOD | Attribute::IS_REPEATABLE)]
final readonly class Requires
{
    public function __construct(public string $expression)
    {
    }
}
