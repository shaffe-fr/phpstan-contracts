<?php

declare(strict_types=1);

namespace PhpstanContracts\Attribute;

use Attribute;

/**
 * Declares an exception the method throws, and the condition on its inputs under
 * which it does. A `throw` of that class must only be reachable under the
 * condition, and when the condition holds the method must not return. Without a
 * condition, the method may throw the exception on any path.
 */
#[Attribute(Attribute::TARGET_METHOD | Attribute::IS_REPEATABLE)]
final readonly class Throws
{
    /**
     * @param class-string<\Throwable> $class
     */
    public function __construct(public string $class, public ?string $when = null)
    {
    }
}
