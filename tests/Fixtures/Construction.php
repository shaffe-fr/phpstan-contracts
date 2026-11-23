<?php

declare(strict_types=1);

namespace PhpstanContracts\Tests\Fixtures;

use PhpstanContracts\Attribute\Ensures;

final readonly class OldStyle
{
    #[\Deprecated]
    public function __construct(public int $x)
    {
    }
}

final readonly class Sealed
{
    private function __construct(public int $x)
    {
    }

    #[Ensures('$result->x === $x')]
    public static function of(int $x): self
    {
        return new self($x);
    }
}

final class Noisy
{
    public function __construct(public readonly int $a)
    {
    }

    public function __destruct()
    {
        throw new \RuntimeException('destructor ran');
    }
}

final readonly class Plain
{
    public function __construct(public int $x)
    {
    }
}

/**
 * `new` runs the constructor, which must be callable here and must not trigger a
 * deprecation, and later the destructor, which the proof does not see.
 */
final class Construction
{
    #[Ensures('$result === 1')]
    public static function buildsWithADeprecatedConstructor(): int
    {
        return (new OldStyle(1))->x;
    }

    #[Ensures('$result === 1')]
    public static function buildsWithAPrivateConstructor(): int
    {
        return (new Sealed(1))->x;
    }

    #[Ensures('$result === 1')]
    public static function buildsAClassWithADestructor(): int
    {
        return (new Noisy(1))->a;
    }

    #[Ensures('$result === 1')]
    public static function buildsAPlainClass(): int
    {
        return (new Plain(1))->x;
    }
}
