<?php

declare(strict_types=1);

namespace PhpstanContracts\Tests\Unit;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * The package proves, for every pair of ints, four laws about `intdiv()` and `%`
 * (see `IntegerFixture::divisionAndModuloObeyTheLawsOfTruncatedDivision`). Those laws
 * single out one quotient and one remainder, so the proof shows that the translation
 * is PHP's **if PHP itself obeys them**.
 *
 * This test checks that premise on PHP, on the pairs where an implementation could
 * differ: the signs, the extremes, and the neighbours of zero. It needs no solver.
 */
final class PhpIntegerDivisionTest extends TestCase
{
    /**
     * @return iterable<string, array{int, int}>
     */
    public static function operands(): iterable
    {
        $dividends = [0, 1, -1, 7, -7, 100, -100, 12345678901, -12345678901, PHP_INT_MAX, PHP_INT_MAX - 1, PHP_INT_MIN + 1, PHP_INT_MIN];
        $divisors = [1, -1, 2, -2, 3, -3, 7, -7, 1000, -1000, PHP_INT_MAX, PHP_INT_MIN + 1, PHP_INT_MIN];

        foreach ($dividends as $a) {
            foreach ($divisors as $b) {
                if ($a === PHP_INT_MIN && $b === -1) {
                    continue; // intdiv() throws ArithmeticError: this is the overflow check of the package.
                }
                yield sprintf('%d and %d', $a, $b) => [$a, $b];
            }
        }
    }

    #[DataProvider('operands')]
    public function testPhpObeysTheLawsThatTheProofRelyOn(int $a, int $b): void
    {
        $quotient = intdiv($a, $b);
        $remainder = $a % $b;
        $product = $quotient * $b;

        self::assertSame($a, $product + $remainder, 'q * b + r = a');

        // |b| is not an int when b is PHP_INT_MIN, but then every remainder is smaller.
        if ($b !== PHP_INT_MIN) {
            self::assertLessThan(abs($b), $remainder < 0 ? -$remainder : $remainder, '|r| < |b|');
        }

        self::assertTrue($remainder === 0 || ($remainder < 0) === ($a < 0), 'the remainder takes the sign of the dividend');

        // |q * b| <= |a|, written without abs() because |PHP_INT_MIN| is not an int.
        self::assertTrue($a < 0 ? ($product >= $a && $product <= 0) : ($product <= $a && $product >= 0), 'the quotient is truncated, not rounded');
    }
}
