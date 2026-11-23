<?php

declare(strict_types=1);

namespace PhpstanContracts\Tests\Fixtures;

use PhpstanContracts\Attribute\Ensures;

/**
 * Not a subclass of PropertyShadowParent, so `$c->x` here is the public $x of
 * PropertyShadowChild, which is declared in PropertyShadowParent.php.
 */
final class PropertyShadowReader
{
    #[Ensures('$result === $c->x')]
    public static function readsThePropertyOfTheChild(PropertyShadowChild $c): int
    {
        return $c->x;
    }
}
