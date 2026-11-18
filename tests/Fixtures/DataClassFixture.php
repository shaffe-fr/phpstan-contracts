<?php

declare(strict_types=1);

namespace PhpstanContracts\Tests\Fixtures;

use PhpstanContracts\Attribute\Ensures;
use PhpstanContracts\Attribute\Pure;
use PhpstanContracts\Attribute\Requires;

final class DataClassFixture
{
    #[Ensures('$result === $point->x')]
    public static function readsAField(Point $point): int
    {
        return $point->x;
    }

    #[Ensures('$result === $pair->left + $pair->right')]
    #[Ensures('$result >= 0 && $result <= 200')]
    public static function sumOfBoundedFields(Pair $pair): int
    {
        return $pair->left + $pair->right;
    }

    #[Ensures('$result === $segment->start->y')]
    public static function nestedRead(Segment $segment): int
    {
        return $segment->start->y;
    }

    /** @param int<-100, 100> $x */
    #[Ensures('$result->x === $x')]
    #[Ensures('$result->y === 0')]
    public static function buildsAPoint(int $x): Point
    {
        return new Point($x, 0);
    }

    #[Ensures('$result->x === 1 && $result->y === 2')]
    public static function namedArgumentsFollowTheirNames(): Point
    {
        return new Point(y: 2, x: 1);
    }

    #[Ensures('$result->x === $point->y && $result->y === $point->x')]
    public static function swapsTheCoordinates(Point $point): Point
    {
        return new Point($point->y, $point->x);
    }

    #[Ensures('implies($segment->end === null, $result === 0)')]
    #[Ensures('implies($segment->end !== null, $result === $segment->end->x)')]
    public static function nullsafeRead(Segment $segment): int
    {
        return $segment->end?->x ?? 0;
    }

    #[Ensures('$result === ($segment->end !== null)')]
    public static function nullCheckOnAField(Segment $segment): bool
    {
        return $segment->end !== null;
    }

    #[Ensures('$result === $segment->end->y')]
    #[Requires('$segment->end !== null')]
    public static function narrowedByANullCheck(Segment $segment): int
    {
        if ($segment->end === null) {
            return 0;
        }

        return $segment->end->y;
    }

    #[Ensures('$result >= 0 && $result <= 100')]
    public static function aFieldRangeIsAnAssumptionOnInputs(Percent $percent): int
    {
        return $percent->value;
    }

    /** @param int<0, 1000> $x */
    #[Ensures('$result->value <= 100')]
    public static function aReturnedObjectStaysInRange(int $x): Percent
    {
        return new Percent(min($x, 100));
    }

    #[Ensures('strlen($result) === strlen($tagged->tag)')]
    public static function readsAStringField(Tagged $tagged): string
    {
        return $tagged->tag;
    }

    /** @param int<0, 1000> $x */
    #[Pure]
    public static function aReturnedObjectOutOfRange(int $x): Percent
    {
        return new Percent($x);
    }

    #[Ensures('$result->x > 0')]
    public static function postconditionOnAField(Point $point): Point
    {
        return $point;
    }

    #[Pure]
    public static function constructorWithLogic(int $value): int
    {
        $checked = new Checked($value);

        return $value;
    }

    #[Pure]
    public static function mutableClass(Mutable $mutable): int
    {
        return 1;
    }

    #[Pure]
    public static function privateProperty(Hidden $hidden): int
    {
        return 1;
    }

    #[Pure]
    public static function recursiveClass(Recursive $recursive): int
    {
        return 1;
    }

    #[Pure]
    public static function classWithoutProperties(Nothing $nothing): int
    {
        return 1;
    }

    #[Ensures('$result->id === $id && $result->tag === "x"')]
    public static function buildsADataClassWithAString(int $id): Tagged
    {
        return new Tagged($id, 'x');
    }

    #[Pure]
    public static function constructorThatDoesNotPromote(int $value): int
    {
        $assigned = new Assigned($value);

        return $value;
    }

    #[Pure]
    public static function identityOfObjects(Point $a, Point $b): bool
    {
        return $a === $b;
    }

    #[Pure]
    public static function methodCallOnAnObject(Point $point): int
    {
        return $point->sum();
    }

    #[Pure]
    public static function nullableObjectWithoutACheck(Segment $segment): int
    {
        return $segment->end->x;
    }

    #[Pure]
    public static function everyConstructorArgumentIsRequired(): Point
    {
        return new Point(1);
    }
}
