<?php

declare(strict_types=1);

namespace PhpstanContracts\Tests\Fixtures;

/**
 * Shaped like Spatie\LaravelData\Data: an abstract parent without a constructor,
 * whose inherited properties are neither public nor readonly.
 */
abstract class FrameworkData
{
    use AppendsAdditional;

    protected ?object $_dataContext = null;
}
