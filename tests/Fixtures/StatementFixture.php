<?php

declare(strict_types=1);

namespace PhpstanContracts\Tests\Fixtures;

use PhpstanContracts\Attribute\Pure;

final class StatementFixture
{
    #[Pure]
    public static function whileLoop(int $n): int
    {
        while ($n > 0) {
            $n -= 1;
        }

        return $n;
    }

    #[Pure]
    public static function doWhileLoop(int $n): int
    {
        do {
            $n -= 1;
        } while ($n > 0);

        return $n;
    }

    #[Pure]
    public static function forLoop(int $n): int
    {
        for ($i = 0; $i < $n; $i += 1) {
            $n -= 1;
        }

        return $n;
    }

    #[Pure]
    public static function switchStatement(int $n): int
    {
        switch ($n) {
            case 1:
                return 1;
            default:
                return 2;
        }
    }

    #[Pure]
    public static function tryCatch(int $n): int
    {
        try {
            return $n;
        } catch (\Throwable) {
            return 0;
        }
    }

    #[Pure]
    public static function throwStatement(int $n): int
    {
        if ($n < 0) {
            throw new \InvalidArgumentException('negative');
        }

        return $n;
    }

    #[Pure]
    public static function increment(int $n): int
    {
        $n++;

        return $n;
    }

    #[Pure]
    public static function incrementOnAString(string $s): string
    {
        $s++;

        return $s;
    }

    #[Pure]
    public static function echoStatement(int $n): int
    {
        echo $n;

        return $n;
    }

    /** @return int|null */
    #[Pure]
    public static function bareReturn(int $n)
    {
        if ($n > 0) {
            return;
        }

        return 1;
    }

    #[Pure]
    public static function variableChangesType(int $n): int
    {
        $x = 1;
        $x = $n > 0;

        return 1;
    }

    /** @param int<0, 10> $n */
    #[Pure]
    public static function variableDefinedInOneBranchOnly(int $n): int
    {
        if ($n > 5) {
            $x = 1;
        }

        return $x;
    }

    #[Pure]
    public function writesAProperty(int $n): int
    {
        $this->value = $n;

        return $n;
    }

    public int $value = 0;

    #[Pure]
    public static function callsAnotherMethod(int $n): int
    {
        return self::helper($n);
    }

    private static function helper(int $n): int
    {
        return $n;
    }

    #[Pure]
    public static function callsAnUnknownFunction(string $text): int
    {
        return strlen(strtoupper($text));
    }

    #[Pure]
    public static function closure(int $n): int
    {
        $f = static fn (int $x): int => $x + 1;

        return $n;
    }

    #[Pure]
    public static function arrayLiteral(int $n): int
    {
        $items = [1, 2, 3];

        return $n;
    }

    #[Pure]
    public static function castToString(int $n): string
    {
        return (string) $n;
    }

    #[Pure]
    public static function shortTernary(int $n): int
    {
        return $n ?: 1;
    }

    #[Pure]
    public static function power(int $n): int
    {
        return $n ** 2;
    }

    #[Pure]
    public static function spaceship(int $a, int $b): int
    {
        return $a <=> $b;
    }

    #[Pure]
    public static function truthiness(int $n): int
    {
        if ($n) {
            return 1;
        }

        return 0;
    }

    #[Pure]
    public static function variableVariable(int $n): int
    {
        $name = 'n';

        return $$name;
    }

    #[Pure]
    public static function instanceOfCheck(Point $point): bool
    {
        return $point instanceof Point;
    }

    #[Pure]
    public static function unknownConstant(): int
    {
        return PHP_EOL === "\n" ? 1 : 0;
    }

    #[Pure]
    public static function matchWithOnlyADefault(int $n): int
    {
        return match ($n) {
            default => 1,
        };
    }
}
