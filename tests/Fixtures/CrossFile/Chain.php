<?php

declare(strict_types=1);

namespace PhpstanContracts\Tests\Fixtures\CrossFile;

/**
 * Helpers past the inlining limits: depth1() starts a chain of 17 nested calls, and
 * level1() reaches level5() through 4 + 16 + 64 + 256 calls.
 */
final class Chain
{
    public static function depth1(int $x): int
    {
        return self::depth2($x);
    }

    public static function depth2(int $x): int
    {
        return self::depth3($x);
    }

    public static function depth3(int $x): int
    {
        return self::depth4($x);
    }

    public static function depth4(int $x): int
    {
        return self::depth5($x);
    }

    public static function depth5(int $x): int
    {
        return self::depth6($x);
    }

    public static function depth6(int $x): int
    {
        return self::depth7($x);
    }

    public static function depth7(int $x): int
    {
        return self::depth8($x);
    }

    public static function depth8(int $x): int
    {
        return self::depth9($x);
    }

    public static function depth9(int $x): int
    {
        return self::depth10($x);
    }

    public static function depth10(int $x): int
    {
        return self::depth11($x);
    }

    public static function depth11(int $x): int
    {
        return self::depth12($x);
    }

    public static function depth12(int $x): int
    {
        return self::depth13($x);
    }

    public static function depth13(int $x): int
    {
        return self::depth14($x);
    }

    public static function depth14(int $x): int
    {
        return self::depth15($x);
    }

    public static function depth15(int $x): int
    {
        return self::depth16($x);
    }

    public static function depth16(int $x): int
    {
        return self::depth17($x);
    }

    public static function depth17(int $x): int
    {
        return $x;
    }

    public static function level1(int $x): int
    {
        return self::level2($x) + self::level2($x) + self::level2($x) + self::level2($x);
    }

    public static function level2(int $x): int
    {
        return self::level3($x) + self::level3($x) + self::level3($x) + self::level3($x);
    }

    public static function level3(int $x): int
    {
        return self::level4($x) + self::level4($x) + self::level4($x) + self::level4($x);
    }

    public static function level4(int $x): int
    {
        return self::level5($x) + self::level5($x) + self::level5($x) + self::level5($x);
    }

    public static function level5(int $x): int
    {
        return $x;
    }
}
