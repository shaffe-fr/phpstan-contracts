<?php

declare(strict_types=1);

namespace PhpstanContracts\Tests\Solver;

use PhpstanContracts\Smt\Smt;
use PhpstanContracts\Tests\Support\RequiresZ3;
use PhpstanContracts\Tests\Support\Z3Evaluator;
use PHPUnit\Framework\TestCase;

/**
 * The prover is only as good as its model of PHP. Each test here computes the
 * same operation in PHP and in Z3, through the terms the translator emits, and
 * requires the same answer, bit for bit.
 */
final class SemanticsTest extends TestCase
{
    use RequiresZ3;

    private Z3Evaluator $z3;

    protected function setUp(): void
    {
        $this->z3 = new Z3Evaluator($this->requireZ3());
    }

    /**
     * The laws of truncated division, proven for every pair of ints by
     * `IntegerFixture::divisionAndModuloObeyTheLawsOfTruncatedDivision`, leave out this one
     * pair: its quotient is not an int, and PHP throws.
     */
    public function testIntegerDivisionOfTheMinimumByMinusOneLeavesTheIntRange(): void
    {
        [$quotient] = $this->z3->evaluate([sprintf('(php_intdiv %s %s)', Smt::int(PHP_INT_MIN)->smt, Smt::int(-1)->smt)]);

        self::assertSame('9223372036854775808', Z3Evaluator::integer($quotient), 'PHP throws ArithmeticError here, which is the overflow obligation');
    }

    public function testFloatArithmeticIsIeeeLikePhp(): void
    {
        $terms = [];
        $expected = [];
        foreach (self::floatPairs() as [$a, $b]) {
            $x = Smt::floatLiteral($a)->smt;
            $y = Smt::floatLiteral($b)->smt;

            $terms[] = "(fp.add RNE $x $y)";
            $expected[] = Z3Evaluator::phpFloatBits($a + $b);
            $terms[] = "(fp.sub RNE $x $y)";
            $expected[] = Z3Evaluator::phpFloatBits($a - $b);
            $terms[] = "(fp.mul RNE $x $y)";
            $expected[] = Z3Evaluator::phpFloatBits($a * $b);
            if ($b !== 0.0) {
                $terms[] = "(fp.div RNE $x $y)";
                $expected[] = Z3Evaluator::phpFloatBits($a / $b);
            }
        }

        $actual = array_map(Z3Evaluator::floatBits(...), $this->z3->evaluate($terms));

        $this->assertSameResults($terms, $expected, $actual);
    }

    public function testFloatFunctionsAreIeeeLikePhp(): void
    {
        $terms = [];
        $expected = [];
        foreach (self::floatValues() as $a) {
            $x = Smt::floatLiteral($a)->smt;

            $terms[] = "(fp.neg $x)";
            $expected[] = Z3Evaluator::phpFloatBits(-$a);
            $terms[] = "(fp.abs $x)";
            $expected[] = Z3Evaluator::phpFloatBits(abs($a));
            $terms[] = "(fp.sqrt RNE $x)";
            $expected[] = Z3Evaluator::phpFloatBits(sqrt($a));
            $terms[] = "(fp.roundToIntegral RTN $x)";
            $expected[] = Z3Evaluator::phpFloatBits(floor($a));
            $terms[] = "(fp.roundToIntegral RTP $x)";
            $expected[] = Z3Evaluator::phpFloatBits(ceil($a));
        }

        $actual = array_map(Z3Evaluator::floatBits(...), $this->z3->evaluate($terms));

        $this->assertSameResults($terms, $expected, $actual);
    }

    public function testFloatComparisonsAreIeeeLikePhp(): void
    {
        $terms = [];
        $expected = [];
        foreach (self::floatPairs() as [$a, $b]) {
            $x = Smt::floatLiteral($a)->smt;
            $y = Smt::floatLiteral($b)->smt;

            foreach ([['fp.lt', $a < $b], ['fp.leq', $a <= $b], ['fp.gt', $a > $b], ['fp.geq', $a >= $b], ['fp.eq', $a === $b]] as [$function, $result]) {
                $terms[] = "($function $x $y)";
                $expected[] = $result ? 'true' : 'false';
            }
        }

        $actual = array_map(static fn (string|array $value): string => is_string($value) ? $value : json_encode($value, JSON_THROW_ON_ERROR), $this->z3->evaluate($terms));

        $this->assertSameResults($terms, $expected, $actual);
    }

    public function testNanAndSignedZeroEqualityDifferFromStructuralEquality(): void
    {
        $nan = Smt::floatLiteral(NAN)->smt;
        $zero = Smt::floatLiteral(0.0)->smt;
        $negativeZero = Smt::floatLiteral(-0.0)->smt;

        [$nanIsNan, $nanStructural, $zerosIeee, $zerosStructural] = $this->z3->evaluate([
            "(fp.eq $nan $nan)",
            "(= $nan $nan)",
            "(fp.eq $zero $negativeZero)",
            "(= $zero $negativeZero)",
        ]);

        self::assertSame('false', $nanIsNan, 'NAN === NAN is false in PHP');
        self::assertSame('true', $nanStructural, 'which is why the translator never uses = for ===');
        self::assertSame('true', $zerosIeee, '0.0 === -0.0 is true in PHP');
        self::assertSame('false', $zerosStructural);
    }

    public function testIntToFloatConversionRoundsToNearestEvenLikePhp(): void
    {
        $values = [0, 1, -1, 9007199254740993, -9007199254740993, 9007199254740995, PHP_INT_MAX, PHP_INT_MIN, PHP_INT_MAX - 1];
        mt_srand(11);
        for ($i = 0; $i < 200; ++$i) {
            $values[] = mt_rand(0, 3) === 0 ? mt_rand(PHP_INT_MIN, PHP_INT_MAX) : mt_rand(-1000000, 1000000);
        }

        $terms = array_map(static fn (int $v): string => Smt::intToFloat(Smt::int($v))->smt, $values);
        $expected = array_map(static fn (int $v): string => Z3Evaluator::phpFloatBits((float) $v), $values);

        $actual = array_map(Z3Evaluator::floatBits(...), $this->z3->evaluate($terms));

        $this->assertSameResults($terms, $expected, $actual);
    }

    public function testIntPlusFloatConvertsTheIntFirstLikePhp(): void
    {
        $terms = [];
        $expected = [];
        foreach ([[9007199254740993, 0.0], [PHP_INT_MAX, 1.0], [-5, 0.5], [3, -0.0]] as [$int, $float]) {
            $terms[] = sprintf('(fp.add RNE %s %s)', Smt::intToFloat(Smt::int($int))->smt, Smt::floatLiteral($float)->smt);
            $expected[] = Z3Evaluator::phpFloatBits($int + $float);
        }

        $actual = array_map(Z3Evaluator::floatBits(...), $this->z3->evaluate($terms));

        $this->assertSameResults($terms, $expected, $actual);
    }

    public function testFloatToIntTruncatesTowardZeroLikePhp(): void
    {
        $values = [0.0, -0.0, 0.5, -0.5, 1.5, -1.5, 2.5, 19.99 * 100.0, 1e15, -1e15, 9.2e18, -9.2e18, 123456789.987, -123456789.987, 4503599627370497.0];
        mt_srand(13);
        for ($i = 0; $i < 150; ++$i) {
            $values[] = (mt_rand() / mt_getrandmax() - 0.5) * 10 ** mt_rand(0, 18);
        }

        $terms = array_map(static fn (float $v): string => Smt::floatToInt(Smt::floatLiteral($v))->smt, $values);
        $expected = array_map(static fn (float $v): string => (string) (int) $v, $values);

        $actual = array_map(Z3Evaluator::integer(...), $this->z3->evaluate($terms));

        $this->assertSameResults($terms, $expected, $actual);
    }

    public function testEveryByteIsOneCharacterWithTheSameCode(): void
    {
        $terms = [];
        for ($byte = 0; $byte < 256; ++$byte) {
            $terms[] = sprintf('(str.to_code %s)', Smt::stringLiteral(chr($byte))->smt);
            $terms[] = sprintf('(str.len %s)', Smt::stringLiteral(chr($byte))->smt);
        }

        $actual = array_map(Z3Evaluator::integer(...), $this->z3->evaluate($terms));

        $expected = [];
        for ($byte = 0; $byte < 256; ++$byte) {
            $expected[] = (string) $byte;
            $expected[] = '1';
        }

        $this->assertSameResults($terms, $expected, $actual);
    }

    public function testStringFunctionsCountBytesLikePhp(): void
    {
        $strings = ['', 'a', 'abc', 'abcabc', 'xx', 'xxx', "caf\xc3\xa9", "\x00\x01", 'a"b', 'a\\b', 'STAFF-ABC123', '.jpg', 'shell.png.php', '€', "\xff\xfe"];

        $terms = [];
        $expected = [];
        foreach ($strings as $haystack) {
            $h = Smt::stringLiteral($haystack)->smt;
            $terms[] = "(str.len $h)";
            $expected[] = (string) strlen($haystack);

            foreach ($strings as $needle) {
                $n = Smt::stringLiteral($needle)->smt;
                $terms[] = "(str.prefixof $n $h)";
                $expected[] = str_starts_with($haystack, $needle) ? 'true' : 'false';
                $terms[] = "(str.suffixof $n $h)";
                $expected[] = str_ends_with($haystack, $needle) ? 'true' : 'false';
                $terms[] = "(str.contains $h $n)";
                $expected[] = str_contains($haystack, $needle) ? 'true' : 'false';
                $terms[] = "(str.len (str.++ $h $n))";
                $expected[] = (string) strlen($haystack . $needle);
                $terms[] = "(= $h $n)";
                $expected[] = $haystack === $needle ? 'true' : 'false';
            }
        }

        $actual = array_map(static fn (string|array $value): string => Z3Evaluator::integer($value), $this->z3->evaluate($terms));

        $this->assertSameResults($terms, $expected, $actual);
    }

    /**
     * @return list<float>
     */
    private static function floatValues(): array
    {
        $values = [
            0.0, -0.0, 1.0, -1.0, 0.1, 0.2, 0.3, 0.5, 1.5, 2.5, -0.5, -1.5, -2.5, 19.99, 100.0,
            1e308, -1e308, 5e-324, 2.2250738585072014e-308, PHP_FLOAT_MAX, -PHP_FLOAT_MAX, PHP_FLOAT_EPSILON,
            9007199254740993.0, 4503599627370496.5, 123456789.123456789, INF, -INF, NAN,
        ];

        mt_srand(42);
        for ($i = 0; $i < 120; ++$i) {
            $bits = (mt_rand(0, 0x7FFFFFFF) << 32) | mt_rand(0, 0xFFFFFFFF);
            if (mt_rand(0, 1) === 1) {
                $bits |= PHP_INT_MIN;
            }
            $unpacked = unpack('E', pack('J', $bits));
            $values[] = is_array($unpacked) && !is_nan($unpacked[1]) ? $unpacked[1] : 1.0;
        }

        return $values;
    }

    /**
     * @return list<array{float, float}>
     */
    private static function floatPairs(): array
    {
        $values = self::floatValues();
        $pairs = [];
        foreach (array_slice($values, 0, 28) as $a) {
            foreach (array_slice($values, 0, 28) as $b) {
                $pairs[] = [$a, $b];
            }
        }
        for ($i = 28; $i + 1 < count($values); $i += 2) {
            $pairs[] = [$values[$i], $values[$i + 1]];
        }

        return $pairs;
    }

    /**
     * @param list<string> $terms
     * @param list<string> $expected
     * @param list<string> $actual
     */
    private function assertSameResults(array $terms, array $expected, array $actual): void
    {
        self::assertCount(count($expected), $actual);

        $differences = [];
        foreach ($expected as $index => $value) {
            if ($actual[$index] !== $value) {
                $differences[] = sprintf('%s: PHP says %s, Z3 says %s', $terms[$index], $value, $actual[$index]);
            }
        }

        self::assertSame([], array_slice($differences, 0, 8), sprintf('%d of %d results differ from PHP.', count($differences), count($expected)));
    }
}
