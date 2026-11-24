<?php

declare(strict_types=1);

namespace PhpstanContracts\Tests\Solver;

use LogicException;
use PhpstanContracts\Smt\ShapeSort;
use PhpstanContracts\Smt\Smt;
use PhpstanContracts\Smt\Sort;
use PhpstanContracts\Smt\Term;
use PhpstanContracts\Solver\ScriptRenderer;
use PhpstanContracts\Translate\DomainBuilder;
use PhpstanContracts\Translate\Encoding;
use PhpstanContracts\Translate\ListConcat;
use PhpstanContracts\Translate\VarSpec;
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

    public function testIntToStringIsTheDecimalFormLikePhp(): void
    {
        $values = [0, 1, -1, 9, 10, -10, 99, 100, 1000000, -1000000, PHP_INT_MAX, PHP_INT_MIN, PHP_INT_MAX - 1, PHP_INT_MIN + 1];
        mt_srand(17);
        for ($i = 0; $i < 200; ++$i) {
            $values[] = mt_rand(0, 3) === 0 ? mt_rand(PHP_INT_MIN, PHP_INT_MAX) : mt_rand(-100000, 100000);
        }

        $terms = array_map(
            static fn (int $v): string => sprintf('(= (php_int_to_string %s) %s)', Smt::int($v)->smt, Smt::stringLiteral((string) $v)->smt),
            $values,
        );
        $expected = array_fill(0, count($values), 'true');
        $actual = array_map(static fn (string|array $value): string => Z3Evaluator::integer($value), $this->z3->evaluate($terms));

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
     * A shape is built with its fields in the canonical order of its sort, whatever order the
     * literal is written in, and `$shape['key']` is the accessor of that key: PHP reads the
     * value that was stored under the key.
     */
    public function testShapeFieldReadsReturnTheValueOfThatKeyLikePhp(): void
    {
        $fieldSorts = [
            'net' => Sort::int(),
            'label' => Sort::string(),
            'ratio' => Sort::float(),
            'note' => Sort::nullable(Sort::string()),
            'ok' => Sort::bool(),
        ];
        $sort = Sort::shape(ShapeSort::of($fieldSorts), array_keys($fieldSorts));
        $arrays = [
            ['ratio' => -0.0, 'note' => null, 'ok' => true, 'net' => PHP_INT_MIN, 'label' => "a\0\xC3\xA9"],
            ['note' => 'x', 'label' => '', 'net' => PHP_INT_MAX, 'ratio' => NAN, 'ok' => false],
            ['net' => 0, 'label' => 'EUR', 'ratio' => INF, 'note' => '', 'ok' => true],
        ];

        $encoding = new Encoding();
        $encoding->registerSort($sort);
        $terms = [];
        $expected = [];
        foreach ($arrays as $index => $array) {
            $other = $arrays[($index + 1) % count($arrays)];
            // An ite hides the components of the literal, so the read goes through the accessor of the datatype.
            $selected = new Term(
                sprintf('(ite (= 1 1) %s %s)', $this->shapeLiteral($sort, $array)->smt, $this->shapeLiteral($sort, $other)->smt),
                $sort,
            );
            foreach ($array as $key => $value) {
                $terms[] = sprintf('(= %s %s)', Smt::shapeField($selected, $key)->smt, $this->valueTerm($fieldSorts[$key], $value)->smt);
                $expected[] = 'true';
            }
            // The value stored under another key is another value.
            $terms[] = sprintf('(= %s %s)', Smt::shapeField($selected, 'net')->smt, $this->valueTerm(Sort::int(), $other['net'])->smt);
            $expected[] = 'false';
        }

        $actual = array_map(
            static fn (string|array $value): string => is_string($value) ? $value : 'not a bool',
            $this->z3->evaluate($terms, (new ScriptRenderer())->declarations($encoding)),
        );
        $this->assertSameResults($terms, $expected, $actual);
    }

    /**
     * `count()`, `$list[$k]`, `$list[] = $x` (and the copy it leaves alone), `isset($list[$i])` and
     * `$list[$i] ?? $default` against real PHP arrays, for ints at both ends, strings with NUL and
     * non-ASCII bytes, floats with NAN, both zeros and infinities, bools and nullable ints whose
     * `null` elements make `isset()` false. A list built from a literal has junk beyond its length,
     * so every formula must hold for any contents of that junk.
     */
    public function testListOperationsMatchPhpArrays(): void
    {
        $pools = [
            'int' => [Sort::int(), [PHP_INT_MIN, PHP_INT_MAX, -1, 0, 1, 42]],
            'string' => [Sort::string(), ['', "a\0b", "\xC3\xA9", 'EUR', "\xFF", '0']],
            'float' => [Sort::float(), [0.0, -0.0, NAN, INF, -INF, 1.5, PHP_FLOAT_MAX]],
            'bool' => [Sort::bool(), [true, false]],
            'nullable int' => [Sort::nullable(Sort::int()), [null, 0, PHP_INT_MAX, -5]],
        ];

        mt_srand(11);
        $formulas = [];
        foreach ($pools as $name => [$elementSort, $pool]) {
            $listSort = Sort::list($elementSort);
            $encoding = new Encoding();
            $encoding->registerSort($listSort);
            $declarations = [...(new ScriptRenderer())->declarations($encoding), sprintf('(declare-const base %s)', $listSort->smt)];
            $base = Smt::symbol('base', $listSort);
            $default = $pool[array_key_last($pool)];
            $defaultTerm = $this->valueTerm($elementSort->isNullable() ? ($elementSort->inner ?? $elementSort) : $elementSort, $elementSort->isNullable() ? 7 : $default);

            $lists = [[], array_slice($pool, 0, 1), array_slice($pool, 0, 2)];
            for ($i = 0; $i < 8; ++$i) {
                $length = mt_rand(0, 6);
                $list = [];
                for ($k = 0; $k < $length; ++$k) {
                    $list[] = $pool[mt_rand(0, count($pool) - 1)];
                }
                $lists[] = $list;
            }
            $long = [];
            for ($k = 0; $k < 12; ++$k) {
                $long[] = $pool[mt_rand(0, count($pool) - 1)];
            }
            $lists[] = $long;


            foreach ($lists as $php) {
                $term = Smt::listLiteral($listSort, array_map(fn (mixed $value): Term => $this->valueTerm($elementSort, $value), $php), $base);
                $length = count($php);
                $claims = [sprintf('(= %s %d)', Smt::listLength($term)->smt, $length)];

                foreach ($php as $key => $value) {
                    $claims[] = sprintf('(= %s %s)', Smt::listItem($term, Smt::int($key))->smt, $this->valueTerm($elementSort, $value)->smt);
                }

                // `$copy = $list; $copy[] = $x;` leaves `$list` alone.
                $x = $pool[mt_rand(0, count($pool) - 1)];
                $copy = $php;
                $copy[] = $x;
                $appended = Smt::listAppend($term, $this->valueTerm($elementSort, $x));
                $claims[] = sprintf('(= %s %d)', Smt::listLength($appended)->smt, count($copy));
                foreach ($copy as $key => $value) {
                    $claims[] = sprintf('(= %s %s)', Smt::listItem($appended, Smt::int($key))->smt, $this->valueTerm($elementSort, $value)->smt);
                }

                foreach (range(-2, $length + 1) as $index) {
                    $at = Smt::int($index);
                    $element = Smt::listItem($term, $at);
                    $present = $elementSort->isNullable()
                        ? Smt::all(Smt::hasKey($term, $at), Smt::not(Smt::isNone($element)))
                        : Smt::hasKey($term, $at);
                    $claims[] = Smt::eq($present, Smt::bool(isset($php[$index])))->smt;

                    $value = $elementSort->isNullable() ? Smt::unwrap($element) : $element;
                    $coalesced = Smt::ite($present, $value, $defaultTerm);
                    $expected = $php[$index] ?? ($elementSort->isNullable() ? 7 : $default);
                    $claims[] = Smt::eq($coalesced, $this->valueTerm($elementSort->isNullable() ? Sort::int() : $elementSort, $expected))->smt;
                }

                foreach ($claims as $claim) {
                    $formulas[$name][] = [$claim, $declarations];
                }
            }
        }

        foreach ($formulas as $name => $claims) {
            $declarations = $claims[0][1];
            $texts = array_map(static fn (array $claim): string => $claim[0], $claims);
            $failed = [];
            foreach ($this->z3->holds($texts, $declarations) as $position => $holds) {
                if (!$holds) {
                    $failed[] = $texts[$position];
                }
            }
            self::assertSame([], array_slice($failed, 0, 5), sprintf('%d of %d claims about lists of %s differ from PHP.', count($failed), count($texts), $name));
        }
    }

    /**
     * `[...$a, ...$b]`, `[...$a, $x]` and `[$x, ...$a]` against PHP: the spread renumbers the keys
     * from 0, and the axioms of ListConcat must pin down every cell below the length, whatever
     * the junk beyond the length of the two lists.
     */
    public function testSpreadOfListsMatchesPhpArrays(): void
    {
        $pools = [
            'int' => [Sort::int(), [PHP_INT_MIN, PHP_INT_MAX, -1, 0, 1, 42]],
            'string' => [Sort::string(), ['', "a\0b", "\xC3\xA9", 'EUR', "\xFF", '0']],
            'float' => [Sort::float(), [0.0, -0.0, NAN, INF, -INF, 1.5]],
            'nullable int' => [Sort::nullable(Sort::int()), [null, 0, PHP_INT_MAX, -5]],
        ];
        mt_srand(17);
        foreach ($pools as $name => [$elementSort, $pool]) {
            $listSort = Sort::list($elementSort);
            $encoding = new Encoding();
            $encoding->registerSort($listSort);
            $claims = [];
            $pairs = [[[], []], [array_slice($pool, 0, 2), []], [[], array_slice($pool, 0, 2)]];
            for ($i = 0; $i < 8; ++$i) {
                $pairs[] = [$this->randomList($pool), $this->randomList($pool)];
            }
            foreach ($pairs as [$first, $second]) {
                $x = $pool[mt_rand(0, count($pool) - 1)];
                $single = $this->valueTerm($elementSort, $x);
                $a = $this->definedList($encoding, $elementSort, $first);
                $b = $this->definedList($encoding, $elementSort, $second);
                $cases = [
                    [ListConcat::concat($encoding, $a, $b), [...$first, ...$second]],
                    [$encoding->define('list', Smt::listAppend($a, $single)), [...$first, $x]],
                    [ListConcat::concat($encoding, $encoding->define('list', Smt::listLiteral($listSort, [$single], $encoding->fresh('base', $listSort))), $a), [$x, ...$first]],
                ];
                foreach ($cases as [$term, $php]) {
                    $claims[] = sprintf('(= %s %d)', Smt::listLength($term)->smt, count($php));
                    foreach ($php as $key => $value) {
                        $claims[] = sprintf('(= %s %s)', Smt::listItem($term, Smt::int($key))->smt, $this->valueTerm($elementSort, $value)->smt);
                    }
                }
            }
            $declarations = [...(new ScriptRenderer())->declarations($encoding), ...$encoding->commands()];
            $failed = [];
            foreach ($this->z3->holds($claims, $declarations) as $position => $holds) {
                if (!$holds) {
                    $failed[] = $claims[$position];
                }
            }
            self::assertSame([], array_slice($failed, 0, 3), sprintf('%d of %d claims about spread lists of %s differ from PHP.', count($failed), count($claims), $name));
        }
    }
    /**
     * `in_array($needle, $list, true)` and, where the needle and the elements have the same
     * scalar type, `in_array($needle, $list)`, against PHP: floats are equal by IEEE (NAN is never
     * found, -0.0 is found as 0.0), strings compare byte for byte, and a nullable element is
     * compared with a null, an int or a nullable needle after lifting, as the translator does.
     * Each list is checked in a script of its own: with several lists in one script the solver
     * instantiates the quantifiers over all of them.
     */
    public function testInArrayOverListsMatchesPhp(): void
    {
        $pools = [
            'int' => [Sort::int(), [PHP_INT_MIN, PHP_INT_MAX, -1, 0, 1, 42]],
            'string' => [Sort::string(), ['', "a\0b", "\xC3\xA9", 'EUR', "\xFF", '0', '00']],
            'float' => [Sort::float(), [0.0, -0.0, NAN, INF, -INF, 1.5, PHP_FLOAT_MAX]],
            'bool' => [Sort::bool(), [true, false]],
            'nullable int' => [Sort::nullable(Sort::int()), [null, 0, PHP_INT_MAX, -5]],
            'nullable float' => [Sort::nullable(Sort::float()), [null, 0.0, -0.0, NAN, INF, 1.5]],
        ];

        mt_srand(29);
        foreach ($pools as $name => [$elementSort, $pool]) {
            $lists = [[], array_slice($pool, 0, 1), array_slice($pool, 0, 2), $pool];
            for ($i = 0; $i < 4; ++$i) {
                $lists[] = $this->randomList($pool);
            }

            $failed = [];
            $total = 0;
            foreach ($lists as $php) {
                $encoding = new Encoding();
                $encoding->registerSort(Sort::list($elementSort));
                $encoding->registerSort(Sort::nullable($elementSort->isNullable() ? ($elementSort->inner ?? $elementSort) : $elementSort));
                $list = $this->definedList($encoding, $elementSort, $php);

                $claims = [];
                foreach ($pool as $needle) {
                    $needleTerm = $this->valueTerm($elementSort, $needle);
                    $found = Smt::listContains($list, static fn (Term $item): Term => Smt::phpEquals($item, $needleTerm));
                    $claims[] = Smt::eq($found, Smt::bool(in_array($needle, $php, true)))->smt;
                    if (!$elementSort->isString() && !$elementSort->isNullable()) {
                        $claims[] = Smt::eq($found, Smt::bool(in_array($needle, $php)))->smt;
                    }

                    if ($elementSort->isNullable() && $needle !== null) {
                        $plain = $this->valueTerm($elementSort->inner ?? $elementSort, $needle);
                        $lifted = Smt::listContains($list, static fn (Term $item): Term => Smt::phpEquals($item, Smt::some($plain)));
                        $claims[] = Smt::eq($lifted, Smt::bool(in_array($needle, $php, true)))->smt;
                    }
                    if (!$elementSort->isNullable() && !$elementSort->isBool()) {
                        $maybe = Smt::some($needleTerm);
                        $claims[] = Smt::eq(
                            Smt::listContains($list, static fn (Term $item): Term => Smt::phpEquals(Smt::some($item), $maybe)),
                            Smt::bool(in_array($needle, $php, true)),
                        )->smt;
                        $none = Smt::none($maybe->sort);
                        $claims[] = Smt::eq(
                            Smt::listContains($list, static fn (Term $item): Term => Smt::phpEquals(Smt::some($item), $none)),
                            Smt::bool(false),
                        )->smt;
                    }
                }
                if ($elementSort->isNullable()) {
                    $claims[] = Smt::eq(Smt::listContains($list, static fn (Term $item): Term => Smt::isNone($item)), Smt::bool(in_array(null, $php, true)))->smt;
                }

                $total += count($claims);
                array_push($failed, ...$this->failedClaims($encoding, $claims));
            }

            self::assertSame([], array_slice($failed, 0, 3), sprintf('%d of %d claims about in_array() over lists of %s differ from PHP.', count($failed), $total, $name));
        }
    }

    /**
     * `array_sum()` over a `list<int>` against PHP: the sum of every prefix is `array_sum()` of
     * that prefix, and every prefix sum being an int is exactly `is_int(array_sum($list))`, which
     * is false for `[PHP_INT_MAX, 1, -1]` although its total fits.
     */
    public function testArraySumOverAListOfIntsMatchesPhp(): void
    {
        $pool = [PHP_INT_MIN, PHP_INT_MAX, -1, 0, 1, 2, 42, -42, 1000000, PHP_INT_MAX - 1];
        $lists = [
            [],
            [0],
            [-1, 5, 7],
            [PHP_INT_MAX, 0],
            [PHP_INT_MAX, 1, -1],
            [PHP_INT_MIN, -1],
            [-1, PHP_INT_MIN, 1],
            [PHP_INT_MIN, PHP_INT_MAX],
            [PHP_INT_MAX, PHP_INT_MIN, PHP_INT_MAX],
            [PHP_INT_MAX - 1, 1],
            [PHP_INT_MAX - 1, 2],
        ];
        mt_srand(31);
        for ($i = 0; $i < 25; ++$i) {
            $lists[] = $this->randomList($pool);
        }
        for ($i = 0; $i < 25; ++$i) {
            $small = [];
            for ($k = mt_rand(0, 8); $k > 0; --$k) {
                $small[] = mt_rand(-1000, 1000);
            }
            $lists[] = $small;
        }

        $failed = [];
        $total = 0;
        $floats = 0;
        foreach ($lists as $php) {
            $encoding = new Encoding();
            $encoding->requireListSum();
            $list = $this->definedList($encoding, Sort::int(), $php);
            $claims = [];
            $isInt = is_int(array_sum($php));
            $floats += $isInt ? 0 : 1;
            $inRange = Smt::prefixSumsInRange($list);
            $claims[] = $isInt ? $inRange->smt : Smt::not($inRange)->smt;
            if ($isInt) {
                $claims[] = Smt::eq(Smt::prefixSum($list, Smt::listLength($list)), Smt::int(array_sum($php)))->smt;
                // Every prefix of a list that sums to an int sums to an int, to what PHP says.
                for ($n = 0; $n <= count($php); ++$n) {
                    $claims[] = Smt::eq(Smt::prefixSum($list, Smt::int($n)), Smt::int(array_sum(array_slice($php, 0, $n))))->smt;
                }
            }
            $total += count($claims);
            array_push($failed, ...$this->failedClaims($encoding, $claims));
        }

        self::assertGreaterThan(5, $floats, 'the lists include sums that PHP turns into floats');
        self::assertSame([], array_slice($failed, 0, 3), sprintf('%d of %d claims about array_sum() differ from PHP.', count($failed), $total));
    }

    /**
     * The range lemma of `array_sum()` is an assumption of every check of a method, so it is
     * compared with PHP: for bounded input lists (negative bound, a single value, the empty list,
     * very large bounds) the assertions stay consistent and the sum of every prefix is what PHP
     * computes, inside `[n * lo, n * hi]`.
     */
    public function testArraySumRangeLemmaMatchesPhp(): void
    {
        $bounds = [[-5, 5], [7, 7], [0, 0], [-1000, 3], [0, 4000000000000000000], [-4000000000000000000, 4000000000000000000], [PHP_INT_MIN, PHP_INT_MAX]];
        $domains = new DomainBuilder();
        // Closures, so that the int|float result of PHP is not narrowed to int by the analysis.
        $phpSum = static function (array $values, int $n): int|float {
            $sum = 0;
            foreach (array_slice($values, 0, $n) as $value) {
                $sum += $value;
            }
            return $sum;
        };
        $phpProduct = static fn (int|float $a, int|float $b): int|float => $a * $b;
        mt_srand(31);
        $total = 0;
        $failed = [];
        foreach ($bounds as [$lo, $hi]) {
            $lists = [[], [$lo], [$hi], [$lo, $hi, $lo]];
            for ($i = 0; $i < 4; ++$i) {
                $list = [];
                for ($k = mt_rand(0, 6); $k > 0; --$k) {
                    $list[] = mt_rand(0, 3) === 0 ? ($k % 2 === 0 ? $lo : $hi) : mt_rand($lo, $hi);
                }
                $lists[] = $list;
            }
            foreach ($lists as $php) {
                $spec = new VarSpec(Sort::list(Sort::int()), element: new VarSpec(Sort::int(), $lo, $hi));
                $encoding = new Encoding();
                $input = $encoding->declareInput('xs', $spec->sort);
                $encoding->assert($domains->constraint($spec, $input, true));
                $encoding->registerInput($input, $spec);
                $encoding->requireListSum();
                $elements = array_map(static fn (int $value): Term => Smt::int($value), $php);
                $encoding->assert(Smt::eq($input, Smt::listLiteral($spec->sort, $elements, $encoding->fresh('base', $spec->sort))));

                $claims = [];
                for ($n = 0; $n <= count($php); ++$n) {
                    $sum = $phpSum($php, $n);
                    $low = $phpProduct($n, $lo);
                    $high = $phpProduct($n, $hi);
                    if (is_int($low) && is_int($high)) {
                        self::assertTrue(is_int($sum) && $sum >= $low && $sum <= $high, 'the PHP sum is inside the bounds');
                    }
                    if (is_int($sum)) {
                        $claims[] = Smt::eq(Smt::prefixSum($input, Smt::int($n)), Smt::int($sum))->smt;
                    }
                }
                $total += count($claims);
                array_push($failed, ...$this->failedClaims($encoding, $claims));
                // The assertions are consistent: `false` is not provable from them.
                self::assertSame(['false'], $this->failedClaims($encoding, ['false']), 'the lemma contradicts a list of the domain');
            }
        }

        self::assertGreaterThan(50, $total);
        self::assertSame([], array_slice($failed, 0, 3), sprintf('%d of %d prefix sums differ from PHP.', count($failed), $total));
    }

    /**
     * `$a === $b` on lists of ints, strings, bools and nullable ints against PHP, for equal lists,
     * a prefix, one differing element, different lengths and the empty list, whatever the junk
     * beyond the length of each list. `$a === []` is `count($a) === 0`.
     */
    public function testIdenticalOnListsMatchesPhp(): void
    {
        $pools = [
            'int' => [Sort::int(), [PHP_INT_MIN, PHP_INT_MAX, -1, 0, 1, 42]],
            'string' => [Sort::string(), ['', "a\0b", "\xC3\xA9", 'EUR', "\xFF", '0', '00']],
            'bool' => [Sort::bool(), [true, false]],
            'nullable int' => [Sort::nullable(Sort::int()), [null, 0, PHP_INT_MAX, -5]],
        ];

        mt_srand(31);
        foreach ($pools as $name => [$elementSort, $pool]) {
            $lists = [[], array_slice($pool, 0, 1), array_slice($pool, 0, 2), array_slice($pool, 0, 4)];
            for ($i = 0; $i < 3; ++$i) {
                $lists[] = $this->randomList($pool);
            }
            $pairs = [];
            foreach ($lists as $first) {
                $pairs[] = [$first, $first];
                $pairs[] = [$first, [...$first, $pool[0]]];
                if ($first !== []) {
                    $changed = $first;
                    $changed[array_key_last($changed)] = $pool[mt_rand(0, count($pool) - 1)];
                    $pairs[] = [$first, $changed];
                    $pairs[] = [$first, array_slice($first, 1)];
                }
                $pairs[] = [$first, $lists[mt_rand(0, count($lists) - 1)]];
            }

            $failed = [];
            foreach ($pairs as [$first, $second]) {
                $encoding = new Encoding();
                $encoding->registerSort(Sort::list($elementSort));
                $a = $this->definedList($encoding, $elementSort, $first);
                $b = $this->definedList($encoding, $elementSort, $second);
                $claims = [
                    Smt::eq(Smt::listIdentical($a, $b), Smt::bool($first === $second))->smt,
                    Smt::eq(Smt::listIdentical($b, $a), Smt::bool($first === $second))->smt,
                    Smt::eq(Smt::eq(Smt::listLength($a), Smt::int(0)), Smt::bool($first === []))->smt,
                ];
                array_push($failed, ...$this->failedClaims($encoding, $claims));
            }

            self::assertSame([], array_slice($failed, 0, 3), sprintf('%d of %d pairs of lists of %s differ from PHP.', count($failed), count($pairs), $name));
        }
    }

    /**
     * @param list<string> $claims
     * @return list<string> the claims that Z3 cannot prove
     */
    private function failedClaims(Encoding $encoding, array $claims): array
    {
        $declarations = [...(new ScriptRenderer())->declarations($encoding), ...$encoding->commands()];
        $failed = [];
        foreach ($this->z3->holds($claims, $declarations) as $position => $holds) {
            if (!$holds) {
                $failed[] = $claims[$position];
            }
        }

        return $failed;
    }

    /**
     * @param list<mixed> $values
     */
    private function definedList(Encoding $encoding, Sort $elementSort, array $values): Term
    {
        $elements = [];
        foreach ($values as $value) {
            $elements[] = $this->valueTerm($elementSort, $value);
        }
        $listSort = Sort::list($elementSort);

        return $encoding->define('list', Smt::listLiteral($listSort, $elements, $encoding->fresh('base', $listSort)));
    }
    /**
     * @param list<mixed> $pool
     *
     * @return list<mixed>
     */
    private function randomList(array $pool): array
    {
        $list = [];
        for ($k = mt_rand(0, 5); $k > 0; --$k) {
            $list[] = $pool[mt_rand(0, count($pool) - 1)];
        }

        return $list;
    }
    /**
     * @param array<string, mixed> $values
     */
    private function shapeLiteral(Sort $sort, array $values): Term
    {
        $shape = $sort->shape ?? throw new LogicException('A shape sort is needed.');

        return Smt::shape($sort, array_map(
            fn (string $key): Term => $this->valueTerm($shape->fields[$key], $values[$key]),
            $shape->keys(),
        ));
    }

    private function valueTerm(Sort $sort, mixed $value): Term
    {
        return match (true) {
            $sort->isNullable() && $value === null => Smt::none($sort),
            $sort->isNullable() && $sort->inner !== null => Smt::some($this->valueTerm($sort->inner, $value)),
            is_int($value) => Smt::int($value),
            is_float($value) => Smt::floatLiteral($value),
            is_string($value) => Smt::stringLiteral($value),
            is_bool($value) => Smt::bool($value),
            default => throw new LogicException('Unsupported value.'),
        };
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
