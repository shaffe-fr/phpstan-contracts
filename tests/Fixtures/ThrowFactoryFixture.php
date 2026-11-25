<?php

declare(strict_types=1);

namespace PhpstanContracts\Tests\Fixtures;

use PhpstanContracts\Attribute\Ensures;
use PhpstanContracts\Attribute\Requires;
use PhpstanContracts\Attribute\Throws;

/**
 * Methods that throw an exception built by a static factory.
 */
final class ThrowFactoryFixture
{
    public const int OFFSET = 98_000_000;
    public const int SIZE = 1_000_000;

    #[Throws(FactoryRangeException::class, when: '$webId < 0 || $webId >= self::SIZE')]
    #[Ensures('$result >= self::OFFSET && $result < self::OFFSET + self::SIZE')]
    public static function fromWebId(int $webId): int
    {
        if ($webId < 0 || $webId >= self::SIZE) {
            throw FactoryRangeException::outOfRange($webId, $webId, self::OFFSET, self::SIZE);
        }

        return self::OFFSET + $webId;
    }

    /** The guard lets the first id past the range through. */
    #[Throws(FactoryRangeException::class, when: '$webId < 0 || $webId >= self::SIZE')]
    public static function fromWebIdBroken(int $webId): int
    {
        if ($webId < 0 || $webId > self::SIZE) {
            throw FactoryRangeException::outOfRange($webId, $webId, self::OFFSET, self::SIZE);
        }

        return self::OFFSET + $webId;
    }

    #[Throws(FactoryRangeException::class, when: '$n < 0')]
    public static function namedArguments(int $n): int
    {
        if ($n < 0) {
            throw FactoryRangeException::outOfRange(end: self::SIZE, offset: self::OFFSET, code: $n, id: $n);
        }

        return $n;
    }

    #[Throws(FactoryRangeException::class, when: '$n < 0')]
    public static function lateStaticReturnType(int $n): int
    {
        if ($n < 0) {
            throw FactoryRangeException::atLeast($n);
        }

        return $n;
    }

    #[Throws(FactoryRangeException::class, when: '$n < 0')]
    public static function anIntForAFloatParameter(int $n): int
    {
        if ($n < 0) {
            throw FactoryRangeException::ofFloat($n);
        }

        return $n;
    }

    #[Throws(FactoryRangeException::class, when: '$n < 0')]
    public static function anOptionalParameterLeftOut(int $n): int
    {
        if ($n < 0) {
            throw FactoryRangeException::withDefault($n);
        }

        return $n;
    }

    #[Throws(FactoryRangeException::class, when: '$n < 0')]
    public static function inAMatchArm(int $n): int
    {
        return match (true) {
            $n < 0 => throw FactoryRangeException::atLeast($n),
            default => $n,
        };
    }

    #[Throws(FactoryRangeException::class, when: '$n === null')]
    public static function inACoalesce(?int $n): int
    {
        return $n ?? throw FactoryRangeException::plain();
    }

    #[Throws(FactoryBaseException::class)]
    #[Throws(FactorySpecificException::class, when: '$n < 0')]
    public static function aSpecificDeclarationOnTheSameClass(int $n): int
    {
        if ($n < 0) {
            throw FactorySpecificException::specific();
        }

        return $n;
    }

    #[Throws(FactoryBaseException::class)]
    public static function aDeclarationOnAParentClass(int $n): int
    {
        if ($n < 0) {
            throw FactorySpecificException::specific();
        }

        return $n;
    }

    #[Throws(FactorySpecificException::class)]
    public static function aStaticReturnTypeResolvedToTheCalledClass(int $n): int
    {
        if ($n < 0) {
            throw FactorySpecificException::make();
        }

        return $n;
    }

    #[Throws(FactoryBaseException::class, when: '$n < 0')]
    public static function aNonFinalClassWithoutADeclarationOnItsSubclasses(int $n): int
    {
        if ($n < 0) {
            throw FactoryBaseException::make();
        }

        return $n;
    }

    /** The factory is declared to return the parent: the thrown object may not be the subclass. */
    #[Throws(FactorySpecificException::class)]
    public static function aDeclarationOnlyOnASubclass(int $n): int
    {
        if ($n < 0) {
            throw FactoryBaseException::make();
        }

        return $n;
    }

    #[Throws(FactoryBaseException::class, when: '$n < 0')]
    #[Throws(FactorySpecificException::class, when: '$n > 100')]
    public static function aConditionOnASubclassOfTheDeclaredReturnType(int $n): int
    {
        if ($n < 0) {
            throw FactoryBaseException::make();
        }

        return $n;
    }

    #[Throws(FactoryRangeException::class)]
    public static function anArgumentThatCanOverflow(int $n): int
    {
        throw FactoryRangeException::atLeast($n + 1);
    }

    #[Throws(FactoryRangeException::class)]
    public static function anArgumentThatDividesByZero(int $n): int
    {
        throw FactoryRangeException::atLeast(intdiv(10, $n));
    }

    #[Throws(FactoryRangeException::class, when: '$n < 0')]
    public static function throwsOutsideTheCondition(int $n): int
    {
        if ($n < 10) {
            throw FactoryRangeException::atLeast($n);
        }

        return $n;
    }

    #[Throws(\LogicException::class)]
    public static function notDeclaredForTheClass(int $n): int
    {
        throw FactoryRangeException::atLeast($n);
    }

    #[Throws(FactoryRangeException::class)]
    public static function aFloatForAnIntParameter(int $n): int
    {
        throw FactoryRangeException::atLeast(1.5);
    }

    #[Throws(FactoryRangeException::class)]
    public static function aStringForAnIntParameter(int $n): int
    {
        throw FactoryRangeException::atLeast('1');
    }

    #[Throws(FactoryRangeException::class)]
    public static function anIntForAStringParameter(int $n): int
    {
        throw FactoryRangeException::ofString($n);
    }

    #[Throws(FactoryRangeException::class)]
    public static function aMissingArgument(int $n): int
    {
        throw FactoryRangeException::atLeast();
    }

    #[Throws(FactoryRangeException::class)]
    public static function anUnknownNamedArgument(int $n): int
    {
        throw FactoryRangeException::atLeast(count: $n);
    }

    #[Throws(FactoryRangeException::class)]
    public static function aNonStaticFactory(int $n): int
    {
        throw FactoryRangeException::instance();
    }

    #[Throws(FactoryRangeException::class)]
    public static function aPrivateFactory(int $n): int
    {
        throw FactoryRangeException::hidden();
    }

    #[Throws(FactoryRangeException::class)]
    public static function aFactoryThatDoesNotExist(int $n): int
    {
        throw FactoryRangeException::missing();
    }

    #[Throws(FactoryRangeException::class)]
    public static function aVariable(int $n): int
    {
        throw $exception;
    }

    #[Throws(FactoryRangeException::class)]
    public static function aMethodOfAnObject(int $n): int
    {
        throw $factory->make();
    }

    #[Throws(FactoryRangeException::class)]
    public static function aDynamicClassName(int $n): int
    {
        throw $class::make();
    }

    #[Throws(FactoryRangeException::class)]
    public static function aDynamicMethodName(int $n): int
    {
        throw FactoryRangeException::$method();
    }

    #[Throws(FactoryRangeException::class)]
    public static function aNewOfADynamicClass(int $n): int
    {
        throw new $class('Dynamic.');
    }

    #[Throws(FactoryRangeException::class)]
    public static function aFirstClassCallable(int $n): int
    {
        throw FactoryRangeException::atLeast(...);
    }

    #[Throws(FactoryRangeException::class)]
    public static function aFunctionCall(int $n): int
    {
        throw build_exception($n);
    }

    #[Throws(FactoryRangeException::class)]
    public static function aFactoryWithoutANativeReturnType(int $n): int
    {
        throw FactoryRangeException::noType();
    }

    #[Throws(FactoryRangeException::class)]
    public static function aFactoryDocumentedAsReturningAnException(int $n): int
    {
        throw FactoryRangeException::documentedOnly();
    }

    #[Throws(FactoryRangeException::class)]
    public static function aFactoryReturningSomethingElse(int $n): int
    {
        throw FactoryRangeException::notAnException();
    }

    #[Throws(FactoryRangeException::class)]
    public static function aFactoryReturningAnInterface(int $n): int
    {
        throw FactoryRangeException::asInterface();
    }

    #[Throws(FactoryRangeException::class)]
    public static function aFactoryReturningAUnion(int $n): int
    {
        throw FactoryRangeException::either();
    }

    #[Throws(FactoryRangeException::class)]
    public static function aFactoryReturningNullable(int $n): int
    {
        throw FactoryRangeException::nullable();
    }

    #[Throws(FactoryRangeException::class)]
    public static function aFactoryThatNeverReturns(int $n): int
    {
        throw FactoryRangeException::neverReturns();
    }

    #[Throws(FactoryRangeException::class)]
    public static function aFactoryWithAVariadicParameter(int $n): int
    {
        throw FactoryRangeException::many($n);
    }

    #[Throws(FactoryRangeException::class)]
    public static function aFactoryWithAPrecondition(int $n): int
    {
        throw FactoryContractException::guarded($n);
    }

    #[Throws(FactoryRangeException::class)]
    public static function aFactoryThatDeclaresThrows(int $n): int
    {
        throw FactoryContractException::thatThrows();
    }

    #[Throws(FactoryRangeException::class)]
    public static function throwInsideAnOperation(int $n): int
    {
        return $n + throw FactoryRangeException::plain();
    }
}

final class FactoryRangeException extends \RuntimeException
{
    public static function outOfRange(int $id, int $code, int $offset, int $end): self
    {
        return new self(sprintf('Web id %d: code %d outside %d..%d.', $id, $code, $offset, $end));
    }

    public static function atLeast(int $value): static
    {
        return new static('Too small.');
    }

    public static function ofFloat(float $value): self
    {
        return new self('Float.');
    }

    public static function ofString(string $text): self
    {
        return new self($text);
    }

    public static function withDefault(int $a, int $b = 0): self
    {
        return new self('Default.');
    }

    public static function plain(): self
    {
        return new self('Plain.');
    }

    public function instance(): self
    {
        return $this;
    }

    private static function hidden(): self
    {
        return new self('Hidden.');
    }

    public static function noType()
    {
        return new self('No type.');
    }

    /**
     * @return self
     */
    public static function documentedOnly()
    {
        return new self('Documented only.');
    }

    public static function notAnException(): \stdClass
    {
        return new \stdClass();
    }

    public static function asInterface(): \Throwable
    {
        return new self('Interface.');
    }

    public static function either(): self|\LogicException
    {
        return new self('Union.');
    }

    public static function nullable(): ?self
    {
        return null;
    }

    public static function neverReturns(): never
    {
        throw new self('Never.');
    }

    public static function many(int ...$ids): self
    {
        return new self('Many.');
    }
}
