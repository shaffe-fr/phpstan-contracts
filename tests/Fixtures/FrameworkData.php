<?php

declare(strict_types=1);

namespace PhpstanContracts\Tests\Fixtures;

/**
 * Shaped like the base class of a data framework (Spatie\LaravelData\Data is one): an abstract parent
 * without a constructor, whose inherited properties are neither public nor readonly.
 */
abstract class FrameworkData
{
    use AppendsAdditional;

    protected ?object $_dataContext = null;
}
