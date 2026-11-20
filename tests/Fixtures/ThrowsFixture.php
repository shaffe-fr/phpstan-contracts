<?php

declare(strict_types=1);

namespace PhpstanContracts\Tests\Fixtures;

use PhpstanContracts\Attribute\Ensures;
use PhpstanContracts\Attribute\Pure;
use PhpstanContracts\Attribute\Throws;

/**
 * Methods that guard their input with an exception.
 */
final class ThrowsFixture
{
    public const int OFFSET = 98_000_000;

    public const int SIZE = 1_000_000;

    #[Throws(\InvalidArgumentException::class, when: '$webId < 0 || $webId >= self::SIZE')]
    #[Ensures('$result >= self::OFFSET && $result < self::OFFSET + self::SIZE')]
    #[Ensures('$result === self::OFFSET + $webId')]
    public static function fromWebId(int $webId): int
    {
        if ($webId < 0 || $webId >= self::SIZE) {
            throw new \InvalidArgumentException('The web id is outside the reserved range.');
        }

        return self::OFFSET + $webId;
    }

    /** The guard lets the first id past the range through. */
    #[Throws(\InvalidArgumentException::class, when: '$webId < 0 || $webId >= self::SIZE')]
    #[Ensures('$result < self::OFFSET + self::SIZE')]
    public static function fromWebIdBroken(int $webId): int
    {
        if ($webId < 0 || $webId > self::SIZE) {
            throw new \InvalidArgumentException('The web id is outside the reserved range.');
        }

        return self::OFFSET + $webId;
    }

    /** The guard also refuses the first id of the range. */
    #[Throws(\InvalidArgumentException::class, when: '$webId < 0 || $webId >= self::SIZE')]
    public static function throwsOutsideTheCondition(int $webId): int
    {
        if ($webId <= 0 || $webId >= self::SIZE) {
            throw new \InvalidArgumentException('The web id is outside the reserved range.');
        }

        return self::OFFSET + $webId;
    }

    #[Ensures('$result >= 0 && $result <= 2')]
    public static function anUndeclaredThrowThatIsUnreachable(Suit $suit): int
    {
        return match ($suit) {
            Suit::Hearts => 0,
            Suit::Spades => 1,
            Suit::Clubs => 2,
            default => throw new \LogicException('Unknown suit.'),
        };
    }

    #[Pure]
    public static function anUndeclaredThrowThatIsReachable(int $n): int
    {
        if ($n > 10) {
            throw new \LogicException('Too large.');
        }

        return $n;
    }

    /** A declared parent class allows its subclasses. */
    #[Throws(\LogicException::class, when: '$n < 0')]
    #[Ensures('$result === $n')]
    public static function aSubclassOfTheDeclaredClass(int $n): int
    {
        if ($n < 0) {
            throw new \InvalidArgumentException('Negative.', 3);
        }

        return $n;
    }

    /** Without a condition, the exception may be thrown anywhere, and only returns are checked. */
    #[Throws(\DomainException::class)]
    #[Ensures('$result >= 0')]
    public static function withoutACondition(int $n): int
    {
        if ($n < 0) {
            throw new \DomainException();
        }

        return $n;
    }

    #[Throws(\InvalidArgumentException::class, when: '$value === null')]
    #[Ensures('$result === $value')]
    public static function throwOnTheRightOfACoalesce(?int $value): int
    {
        return $value ?? throw new \InvalidArgumentException('Missing value.');
    }

    /**
     * The path that throws does not reach the division.
     *
     * @param int<0, 100> $total
     */
    #[Throws(\InvalidArgumentException::class, when: '$divisor === 0')]
    #[Ensures('$result === intdiv($total, $divisor)')]
    public static function throwInAnAssignment(int $total, int $divisor): int
    {
        $checked = $divisor !== 0 ? $divisor : throw new \InvalidArgumentException(message: 'Division by zero.');

        return intdiv($total, $checked);
    }

    /** A declared exception that the method never throws. */
    #[Throws(\InvalidArgumentException::class, when: '$n < 0')]
    public static function neverThrows(int $n): int
    {
        return 0;
    }

    #[Pure]
    public static function throwInsideAnOperation(?int $n): int
    {
        return ($n ?? throw new \InvalidArgumentException()) + 1;
    }

    #[Pure]
    public static function throwInACondition(?int $n): int
    {
        if (($n ?? throw new \InvalidArgumentException()) > 0) {
            return 1;
        }

        return 0;
    }

    #[Throws(\LogicException::class)]
    public static function throwsSomethingBuiltElsewhere(int $n): int
    {
        throw self::exception();
    }

    #[Throws(ValidatingException::class)]
    public static function anExceptionWithItsOwnConstructor(int $n): int
    {
        if ($n < 0) {
            throw new ValidatingException();
        }

        return $n;
    }

    #[Throws(\LogicException::class)]
    public static function anExceptionWithoutAConstructor(int $n): int
    {
        if ($n < 0) {
            throw new PlainException('Negative.');
        }

        return $n;
    }

    #[Throws(\LogicException::class)]
    public static function aMessageThatIsNotAString(int $n): int
    {
        if ($n < 0) {
            throw new \LogicException(42);
        }

        return $n;
    }

    #[Throws(\LogicException::class)]
    public static function alwaysThrows(int $n): int
    {
        return $n > 0 ? throw new \LogicException('Positive.') : throw new \LogicException('Not positive.');
    }

    #[Throws(Point::class)]
    public static function declaresSomethingThatIsNotThrowable(int $n): int
    {
        return $n;
    }

    #[Throws(\LogicException::class)]
    public static function callsAMethodThatThrows(int $n): int
    {
        return self::guarded($n);
    }

    private static function guarded(int $n): int
    {
        if ($n < 0) {
            throw new \LogicException('Negative.');
        }

        return $n;
    }

    private static function exception(): \LogicException
    {
        return new \LogicException('Built elsewhere.');
    }
}

final class ValidatingException extends \RuntimeException
{
    public function __construct()
    {
        parent::__construct('Invalid.');
    }
}

final class PlainException extends \LogicException
{
}
