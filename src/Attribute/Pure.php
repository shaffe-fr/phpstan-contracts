<?php

declare(strict_types=1);

namespace PhpstanContracts\Attribute;

use Attribute;

/**
 * Opts a method into verification without any contract: only the absence of
 * runtime errors (overflow, division by zero, unhandled match, missing return)
 * is proven.
 */
#[Attribute(Attribute::TARGET_METHOD)]
final readonly class Pure
{
}
