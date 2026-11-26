<?php

declare(strict_types=1);

namespace CacheProject;

use PhpstanContracts\Attribute\Ensures;

/**
 * Each method reads code of another file that PHPStan does not see it depend on.
 *
 * @phpstan-import-type Share from Shares
 * @mixin \LibExtra
 * @method static int magic(\LibMagic $magic)
 */
final class A
{
    public const OWN = OwnEnd::MAX;

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

    /**
     * @param value-of<Priority> $p
     */
    #[Ensures('$result <= 3')]
    public static function rank(int $p): int
    {
        return $p;
    }

    // Ceiled is a type alias of phpstan.neon.
    /**
     * @param Ceiled $ceiled
     */
    #[Ensures('$result <= 10')]
    public static function ceiled(int $ceiled): int
    {
        return $ceiled;
    }

    /**
     * @param key-of<Keys::MAP> $k
     */
    #[Ensures('$result <= 3')]
    public static function keyed(int $k): int
    {
        return $k;
    }

    /**
     * @param Share $s
     */
    #[Ensures('$result <= 10')]
    public static function share(int $s): int
    {
        return $s;
    }

    /**
     * @template T of Tiers
     * @param class-string<T> $tier
     * @param int<0, T::TOP> $tiered
     */
    #[Ensures('$result <= 10')]
    public static function tier(string $tier, int $tiered): int
    {
        return $tiered;
    }

    // Held fixes the template of its parent Holder with a constant of a third file, which the proof does not read.
    #[Ensures('$result === $held->value')]
    public static function held(\Held $held): int
    {
        return $held->value;
    }

    /**
     * @param class-string<\LibClass> $class
     */
    #[Ensures('$result === 1')]
    public static function classString(string $class): int
    {
        return 1;
    }

    /**
     * @return ($x is positive-int ? int<1, \LibCap::MAX> : int<0, 0>)
     */
    #[Ensures('$result <= 10')]
    public static function conditional(int $x): int
    {
        return $x > 0 ? 1 : 0;
    }

    /**
     * @param int<0, Rank::TOP> $inherited
     */
    #[Ensures('$result <= 10')]
    public static function parentConstant(int $inherited): int
    {
        return $inherited;
    }

    /**
     * @param int<0, Rank::LOW> $used
     */
    #[Ensures('$result <= 10')]
    public static function traitConstant(int $used): int
    {
        return $used;
    }

    /**
     * @param \LibTint::Red $tint
     */
    #[Ensures('$result === $tint')]
    public static function tint(\LibTint $tint): \LibTint
    {
        return $tint;
    }

    /**
     * @param int<0, Chain::MAX> $chained
     */
    #[Ensures('$result <= 10')]
    public static function chained(int $chained): int
    {
        return $chained;
    }

    /**
     * @param int<0, self::OWN> $own
     */
    #[Ensures('$result <= 10')]
    public static function own(int $own): int
    {
        return $own;
    }

    /**
     * @param value-of<Level> $level
     */
    #[Ensures('$result <= 3')]
    public static function level(int $level): int
    {
        return $level;
    }
}
