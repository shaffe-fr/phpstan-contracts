<?php

declare(strict_types=1);

namespace CacheProject;

use PhpstanContracts\Attribute\Ensures;

/**
 * Each method reads code of another file that PHPStan does not see it depend on.
 */
final class A
{
    #[Ensures('$result === E::Old')]
    public static function old(): E
    {
        return E::Old;
    }

    #[Ensures('$result === Limits::MAX')]
    public static function limit(): int
    {
        return 10;
    }

    // `Rate` is not imported: it resolves to the namespace first, then to the global class.
    #[Ensures('$result === Rate::BASE')]
    public static function base(): int
    {
        return 5;
    }

    /**
     * @param int<0, Caps::MAX> $x
     */
    #[Ensures('$result <= 10')]
    public static function capped(int $x): int
    {
        return $x;
    }

    // Box's PHPDoc bounds its amount by a constant of a third file.
    #[Ensures('$result->amount <= 10')]
    public static function boxed(Box $box): Box
    {
        return $box;
    }
}
