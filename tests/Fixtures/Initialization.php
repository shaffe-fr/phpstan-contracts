<?php

declare(strict_types=1);

namespace PhpstanContracts\Tests\Fixtures;

use PhpstanContracts\Attribute\Ensures;
use PhpstanContracts\Attribute\Pure;

/** Neither the class nor its constructor is final: a subclass constructor can skip this one. */
class OpenPoint
{
    public function __construct(public readonly int $x)
    {
    }
}

final class Origin extends OpenPoint
{
    public function __construct()
    {
    }
}

final class LazyInit
{
    public readonly int $x;

    public function __construct(bool $set)
    {
        if ($set) {
            $this->x = 1;
        }
    }
}

class SealedPoint
{
    final public function __construct(public readonly int $x)
    {
    }
}

/**
 * Reading a typed property that no one has set throws an Error, so a property is only
 * modelled when every object of the type has it set.
 */
final class Initialization
{
    /**
     * `new Origin()` is an OpenPoint whose $x was never set.
     */
    #[Pure]
    public static function readsAPropertyOfANonFinalClass(OpenPoint $point): int
    {
        return $point->x;
    }

    #[Pure]
    public static function readsAPropertyPromotedByAParentConstructor(Origin $origin): int
    {
        return $origin->x;
    }

    #[Pure]
    public static function readsAPropertyAssignedInTheConstructorBody(LazyInit $lazy): int
    {
        return $lazy->x;
    }

    #[Ensures('$result === $point->x')]
    public static function readsAPropertyBehindAFinalConstructor(SealedPoint $point): int
    {
        return $point->x;
    }
}
