<?php

declare(strict_types=1);

namespace PhpstanContracts\Tests\Unit\Smt;

use InvalidArgumentException;
use PhpstanContracts\Smt\EnumSort;
use PhpstanContracts\Smt\RecordSort;
use PhpstanContracts\Smt\Smt;
use PhpstanContracts\Smt\Sort;
use PhpstanContracts\Smt\Term;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class SmtTest extends TestCase
{
    /**
     * @return iterable<string, array{int, string}>
     */
    public static function integers(): iterable
    {
        yield 'zero' => [0, '0'];
        yield 'positive' => [42, '42'];
        yield 'negative' => [-5, '(- 5)'];
        yield 'maximum' => [PHP_INT_MAX, '9223372036854775807'];
        yield 'minimum, whose negation overflows' => [PHP_INT_MIN, '(- 9223372036854775808)'];
    }

    #[DataProvider('integers')]
    public function testIntegerLiterals(int $value, string $expected): void
    {
        $term = Smt::int($value);

        self::assertSame($expected, $term->smt);
        self::assertTrue($term->sort->isInt());
        self::assertTrue($term->atomic);
    }

    public function testBooleanFolding(): void
    {
        $x = Smt::symbol('x', Sort::bool());
        $y = Smt::symbol('y', Sort::bool());

        self::assertTrue(Smt::not(Smt::bool(false))->isTrue());
        self::assertTrue(Smt::not(Smt::bool(true))->isFalse());
        self::assertSame('x', Smt::not(Smt::not($x))->smt, 'a double negation cancels');
        self::assertSame('(not x)', Smt::not($x)->smt);

        self::assertTrue(Smt::all()->isTrue(), 'an empty conjunction is true');
        self::assertTrue(Smt::any()->isFalse(), 'an empty disjunction is false');
        self::assertSame('x', Smt::all($x, Smt::bool(true))->smt, 'true is dropped from a conjunction');
        self::assertTrue(Smt::all($x, Smt::bool(false))->isFalse(), 'false absorbs a conjunction');
        self::assertSame('x', Smt::any($x, Smt::bool(false))->smt, 'false is dropped from a disjunction');
        self::assertTrue(Smt::any($x, Smt::bool(true))->isTrue(), 'true absorbs a disjunction');
        self::assertSame('(and x y)', Smt::all($x, $y)->smt);
        self::assertSame('(or x y)', Smt::any($x, $y)->smt);
        self::assertSame('(or (not x) y)', Smt::implies($x, $y)->smt);
    }

    public function testFoldedConjunctionOfASingleSymbolStaysAtomic(): void
    {
        $x = Smt::symbol('x', Sort::bool());

        self::assertTrue(Smt::all($x, Smt::bool(true))->atomic);
        self::assertFalse(Smt::all($x, $x)->atomic);
    }

    public function testStructuralEquality(): void
    {
        $x = Smt::symbol('x', Sort::int());

        self::assertSame('(= x 5)', Smt::eq($x, Smt::int(5))->smt);
        self::assertTrue(Smt::eq(Smt::int(5), Smt::int(5))->isTrue(), 'equal literals fold');
        self::assertTrue(Smt::eq(Smt::int(5), Smt::int(6))->isFalse(), 'different literals fold');
        self::assertTrue(Smt::eq(Smt::int(-5), Smt::int(-5))->isTrue());

        $flag = Smt::symbol('flag', Sort::bool());
        self::assertSame('flag', Smt::eq($flag, Smt::bool(true))->smt, 'comparing a bool with true is the bool');
        self::assertSame('(not flag)', Smt::eq($flag, Smt::bool(false))->smt);
        self::assertSame('flag', Smt::eq(Smt::bool(true), $flag)->smt);
    }

    public function testEqualityBetweenDifferentSortsIsAnError(): void
    {
        $this->expectException(InvalidArgumentException::class);

        Smt::eq(Smt::int(1), Smt::bool(true));
    }

    public function testIfThenElseFolding(): void
    {
        $c = Smt::symbol('c', Sort::bool());
        $a = Smt::symbol('a', Sort::int());
        $b = Smt::symbol('b', Sort::int());

        self::assertSame('(ite c a b)', Smt::ite($c, $a, $b)->smt);
        self::assertSame('a', Smt::ite(Smt::bool(true), $a, $b)->smt);
        self::assertSame('b', Smt::ite(Smt::bool(false), $a, $b)->smt);
        self::assertSame('a', Smt::ite($c, $a, $a)->smt, 'identical branches need no condition');
    }

    public function testIntegerRange(): void
    {
        $x = Smt::symbol('x', Sort::int());

        self::assertSame('(and (>= x 0) (<= x 10))', Smt::inRange($x, 0, 10)->smt);
        self::assertSame(
            '(and (>= x (- 9223372036854775808)) (<= x 9223372036854775807))',
            Smt::inRange($x, PHP_INT_MIN, PHP_INT_MAX)->smt,
        );
    }

    /**
     * @return iterable<string, array{float, string}>
     */
    public static function floats(): iterable
    {
        yield 'one tenth' => [0.1, '(fp #b0 #b01111111011 #b1001100110011001100110011001100110011001100110011010)'];
        yield 'one' => [1.0, '(fp #b0 #b01111111111 #b0000000000000000000000000000000000000000000000000000)'];
        yield 'minus two' => [-2.0, '(fp #b1 #b10000000000 #b0000000000000000000000000000000000000000000000000000)'];
        yield 'positive zero' => [0.0, '(fp #b0 #b00000000000 #b0000000000000000000000000000000000000000000000000000)'];
        yield 'negative zero' => [-0.0, '(fp #b1 #b00000000000 #b0000000000000000000000000000000000000000000000000000)'];
        yield 'smallest subnormal' => [5e-324, '(fp #b0 #b00000000000 #b0000000000000000000000000000000000000000000000000001)'];
        yield 'largest finite' => [PHP_FLOAT_MAX, '(fp #b0 #b11111111110 #b1111111111111111111111111111111111111111111111111111)'];
        yield 'not a number' => [NAN, '(_ NaN 11 53)'];
        yield 'infinity' => [INF, '(_ +oo 11 53)'];
        yield 'negative infinity' => [-INF, '(_ -oo 11 53)'];
    }

    #[DataProvider('floats')]
    public function testFloatLiteralsAreExactBitPatterns(float $value, string $expected): void
    {
        $term = Smt::floatLiteral($value);

        self::assertSame($expected, $term->smt);
        self::assertTrue($term->sort->isFloat());
    }

    /**
     * @return iterable<string, array{string, string}>
     */
    public static function strings(): iterable
    {
        yield 'empty' => ['', '""'];
        yield 'printable ASCII' => ['abc 123', '"abc 123"'];
        yield 'double quote' => ['a"b', '"a\u{22}b"'];
        yield 'backslash' => ['a\\b', '"a\u{5c}b"'];
        yield 'NUL byte' => ["a\0b", '"a\u{0}b"'];
        yield 'newline' => ["a\nb", '"a\u{a}b"'];
        yield 'UTF-8 character is its bytes' => ['é', '"\u{c3}\u{a9}"'];
        yield 'euro sign is three bytes' => ['€', '"\u{e2}\u{82}\u{ac}"'];
    }

    #[DataProvider('strings')]
    public function testStringLiteralsAreByteStrings(string $value, string $expected): void
    {
        self::assertSame($expected, Smt::stringLiteral($value)->smt);
    }

    public function testFloatEqualityIsIeeeAndNotStructural(): void
    {
        $a = Smt::symbol('a', Sort::float());
        $b = Smt::symbol('b', Sort::float());

        self::assertSame('(fp.eq a b)', Smt::phpEquals($a, $b)->smt);
        self::assertSame('(= a b)', Smt::eq($a, $b)->smt, 'definitions stay structural');
    }

    public function testNullableEqualityComparesPayloadsOnlyWhenBothArePresent(): void
    {
        $nullable = Sort::nullable(Sort::float());
        $a = Smt::symbol('a', $nullable);
        $b = Smt::symbol('b', $nullable);

        $equality = Smt::phpEquals($a, $b)->smt;

        self::assertStringContainsString('(= a none_Opt_Float64)', $equality);
        self::assertStringContainsString('(fp.eq (val_Opt_Float64 a) (val_Opt_Float64 b))', $equality);
    }

    public function testNullableConstructorsAndAccessors(): void
    {
        $sort = Sort::nullable(Sort::int());
        $x = Smt::symbol('x', $sort);

        self::assertSame('Opt_Int', $sort->smt);
        self::assertSame('none_Opt_Int', Smt::none($sort)->smt);
        self::assertSame('(some_Opt_Int 5)', Smt::some(Smt::int(5))->smt);
        self::assertSame('(= x none_Opt_Int)', Smt::isNone($x)->smt);
        self::assertSame('(val_Opt_Int x)', Smt::unwrap($x)->smt);
        self::assertTrue(Smt::unwrap($x)->sort->isInt());
    }

    public function testRecordConstructorAndAccessors(): void
    {
        $record = new RecordSort('App\\Money', 'R_App_Money', ['cents' => Sort::int(), 'currency' => Sort::string()], ['cents', 'currency'], null);
        $sort = Sort::record($record);
        $money = Smt::symbol('m', $sort);

        self::assertSame('(mk_R_App_Money 5 "EUR")', Smt::construct($sort, [Smt::int(5), Smt::stringLiteral('EUR')])->smt);
        self::assertSame('(R_App_Money/cents m)', Smt::field($money, 'cents')->smt);
        self::assertTrue(Smt::field($money, 'currency')->sort->isString());
    }

    public function testEnumSortNaming(): void
    {
        $enum = new EnumSort('App\\Tier', 'E_App_Tier', ['Gold' => 15, 'Silver' => 5], 'int');
        $sort = Sort::enum($enum);

        self::assertSame('E_App_Tier', $sort->smt);
        self::assertSame('C_App_Tier/Gold', $enum->constructorOf('Gold'));
        self::assertSame('Tier::Silver', $enum->displayConstructor('C_App_Tier/Silver'));
        self::assertSame('Tier', $sort->describe());
    }

    public function testSortDescriptions(): void
    {
        self::assertSame('int', Sort::int()->describe());
        self::assertSame('float', Sort::float()->describe());
        self::assertSame('?string', Sort::nullable(Sort::string())->describe());
        self::assertSame('null', Sort::null()->describe());
        self::assertTrue(Sort::int()->equals(Sort::int()));
        self::assertFalse(Sort::int()->equals(Sort::float()));
        self::assertTrue(Sort::nullable(Sort::int())->isNullable());
    }

    public function testFloatToIntegerConversionUsesTruncation(): void
    {
        $x = Smt::symbol('x', Sort::float());

        self::assertSame('(sbv_to_int ((_ fp.to_sbv 64) RTZ x))', Smt::floatToInt($x)->smt);
        self::assertSame('((_ to_fp 11 53) RNE (to_real n))', Smt::intToFloat(Smt::symbol('n', Sort::int()))->smt);
    }

    public function testByteStringConstraint(): void
    {
        $term = Smt::isByteString(Smt::symbol('s', Sort::string()));

        self::assertSame('(str.in_re s (re.* (re.range "\u{0}" "\u{ff}")))', $term->smt);
        self::assertInstanceOf(Term::class, $term);
    }
}
