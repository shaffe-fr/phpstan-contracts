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
    public static function buildsAnObject(int $x): Position
    {
        return new Position($x, 0);
    }

    #[Ensures('$result->x === 1 && $result->y === 2')]
    public static function namedArgumentsFollowTheirNames(): Position
    {
        return new Position(y: 2, x: 1);
    }

    #[Ensures('$result->x === $point->y && $result->y === $point->x')]
    public static function swapsTheCoordinates(Point $point): Position
    {
        return new Position($point->y, $point->x);
    }

    /**
     * Point is declared in Point.php. Its constructor body could gain logic without
     * PHPStan re-analysing this file, so the proof could go stale.
     */
    #[Ensures('$result->x === 1')]
    public static function buildsAClassOfAnotherFile(): Point
    {
        return new Point(1, 2);
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
    public static function everyConstructorArgumentIsRequired(): Position
    {
        return new Position(1);
    }

    #[Ensures('$result === ($context->quantity > 0)')]
    public static function inheritedStateThatIsNeverRead(PriceContext $context): bool
    {
        return $context->quantity > 0;
    }

    #[Ensures('$result === $object->limit')]
    public static function readsTheModelledPartOfAnObject(PartlyMutable $object): int
    {
        return $object->limit;
    }

    #[Pure]
    public static function readsAMutableProperty(PartlyMutable $object): int
    {
        return $object->count;
    }

    #[Pure]
    public static function readsAPropertyOfAnUnsupportedType(PartlyMutable $object): bool
    {
        return $object->tags === [];
    }

    #[Pure]
    public static function buildsAnObjectWithAnUnmodelledProperty(): PartlyMutable
    {
        return new PartlyMutable(1, []);
    }

    #[Pure]
    public static function nullsafeReadOfAMutableProperty(?PartlyMutable $object): ?int
    {
        return $object?->count;
    }

    #[Pure]
    public static function issetOfAMutableProperty(PartlyMutable $object): bool
    {
        return isset($object->count);
    }

    #[Pure]
    public static function onlyAPropertyOfAnUnsupportedType(OnlyTags $object): int
    {
        return 1;
    }

    #[Pure]
    public static function holdsARecursiveClass(HoldsRecursive $object): int
    {
        return $object->count;
    }
}

/** The data classes below are built by the methods above, so they are declared in this file. */
final readonly class Position
{
    public function __construct(
        public int $x,
        public int $y,
    ) {
    }
}

final readonly class Percent
{
    public function __construct(
        /** @var int<0, 100> */
        public int $value,
    ) {
    }
}

final readonly class Checked
{
    public function __construct(public int $value)
    {
        if ($value < 0) {
            throw new \InvalidArgumentException('negative');
        }
    }
}

final class Tagged
{
    public function __construct(
        public readonly int $id,
        public readonly string $tag,
    ) {
    }
}

final class Assigned
{
    public readonly int $value;

    public function __construct(int $value)
    {
        $this->value = $value;
    }
}
