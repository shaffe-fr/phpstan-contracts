<?php

declare(strict_types=1);

namespace PhpstanContracts\Tests\Fixtures;

use PhpstanContracts\Attribute\Ensures;
use PhpstanContracts\Attribute\Pure;

/**
 * A private method and a method of the same name in a subclass, which PHP picks
 * differently for `$x->m()` and for `static::m()`.
 */
class ShadowParent
{
    /** `$c->m()` from this class runs the private m() below, not the public one of ShadowChild. */
    #[Ensures('$result === 1')]
    public static function privateMethodOfTheCallingScope(ShadowChild $c): int
    {
        return $c->m();
    }

    #[Ensures('$result === 2')]
    public static function privateMethodOfTheCallingScopeBroken(ShadowChild $c): int
    {
        return $c->m();
    }

    /** Called on ShadowChild, `static::k()` runs ShadowChild::k(), although this k() is private. */
    #[Pure]
    public static function lateStaticCallOfAPrivateMethod(): int
    {
        return static::k();
    }

    private function m(): int
    {
        return 1;
    }

    private static function k(): int
    {
        return 1;
    }
}

final class ShadowChild extends ShadowParent
{
    public function __construct(public readonly int $x)
    {
    }

    public function m(): int
    {
        return 2;
    }

    public static function k(): int
    {
        return 2;
    }
}
