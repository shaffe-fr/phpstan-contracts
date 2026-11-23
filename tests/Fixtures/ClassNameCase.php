<?php

declare(strict_types=1);

namespace PhpstanContracts\Tests\Fixtures;

use PhpstanContracts\Attribute\Ensures;

final class Coordinate
{
    public function __construct(public readonly int $x)
    {
    }
}

/**
 * Class names are case-insensitive in PHP: `coordinate` and `Coordinate` are the same
 * class, so they are the same sort.
 */
final class ClassNameCase
{
    #[Ensures('$result === ($a->x === $b->x)')]
    public static function sameClassInTwoCases(Coordinate $a, coordinate $b): bool
    {
        return $a->x === $b->x;
    }

    #[Ensures('$result->x === $a->x')]
    public static function buildsTheClassInAnotherCase(Coordinate $a): coordinate
    {
        return new COORDINATE($a->x);
    }

    #[Ensures('$result === false')]
    public static function sameClassInTwoCasesBroken(Coordinate $a, COORDINATE $b): bool
    {
        return $a->x === $b->x;
    }
}
