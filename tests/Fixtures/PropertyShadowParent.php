<?php

declare(strict_types=1);

namespace PhpstanContracts\Tests\Fixtures;

use PhpstanContracts\Attribute\Ensures;

/**
 * A private property and a public readonly property of the same name in a subclass.
 * From this class, `$c->x` reads the private $x below, even on a PropertyShadowChild.
 */
class PropertyShadowParent
{
    private int $x = 1;

    /** PHP returns 1 here, the private $x of this class. */
    #[Ensures('$result === 5')]
    public static function readsThePrivatePropertyOfTheCallingClass(): int
    {
        $c = new PropertyShadowChild(5, 2);

        return $c->x;
    }

    #[Ensures('implies($c !== null, $result === 5)')]
    public static function nullsafeReadOfThePrivatePropertyOfTheCallingClass(?PropertyShadowChild $c): ?int
    {
        return $c?->x;
    }

    /** This class declares no $y, so `$c->y` is the property of PropertyShadowChild. */
    #[Ensures('$result === $c->y')]
    public static function readsAPropertyThatThisClassDoesNotDeclare(PropertyShadowChild $c): int
    {
        return $c->y;
    }
}

final class PropertyShadowChild extends PropertyShadowParent
{
    public function __construct(
        public readonly int $x,
        public readonly int $y,
    ) {
    }
}
