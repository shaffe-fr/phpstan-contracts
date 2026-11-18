<?php

declare(strict_types=1);

namespace PhpstanContracts\Tests\Unit\Report;

use PhpstanContracts\Report\ValueFormatter;
use PhpstanContracts\Smt\EnumSort;
use PhpstanContracts\Smt\RecordSort;
use PhpstanContracts\Smt\SExpr;
use PhpstanContracts\Smt\Sort;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class ValueFormatterTest extends TestCase
{
    private ValueFormatter $formatter;

    protected function setUp(): void
    {
        $this->formatter = new ValueFormatter();
    }

    public function testAnUnconstrainedValueIsAny(): void
    {
        self::assertSame('any', $this->formatter->format(Sort::int(), null));
    }

    public function testIntegers(): void
    {
        self::assertSame('42', $this->formatter->format(Sort::int(), '42'));
        self::assertSame('-42', $this->formatter->format(Sort::int(), ['-', '42']));
        self::assertSame('-9223372036854775808', $this->formatter->format(Sort::int(), ['-', '9223372036854775808']));
    }

    public function testBooleans(): void
    {
        self::assertSame('true', $this->formatter->format(Sort::bool(), 'true'));
        self::assertSame('false', $this->formatter->format(Sort::bool(), 'false'));
    }

    /**
     * @return iterable<string, array{string, string}>
     */
    public static function floats(): iterable
    {
        yield 'not a number' => ['(_ NaN 11 53)', 'NAN'];
        yield 'infinity' => ['(_ +oo 11 53)', 'INF'];
        yield 'negative infinity' => ['(_ -oo 11 53)', '-INF'];
        yield 'zero' => ['(_ +zero 11 53)', '0.0'];
        yield 'negative zero' => ['(_ -zero 11 53)', '-0.0'];
        yield 'one tenth, hexadecimal mantissa' => ['(fp #b0 #b01111111011 #x999999999999a)', '0.1'];
        yield 'one tenth, binary mantissa' => ['(fp #b0 #b01111111011 #b1001100110011001100110011001100110011001100110011010)', '0.1'];
        yield 'one' => ['(fp #b0 #b01111111111 #x0000000000000)', '1.0'];
        yield 'minus two' => ['(fp #b1 #b10000000000 #x0000000000000)', '-2.0'];
        yield 'sum that is not 0.3' => ['(fp #b0 #b01111111101 #x3333333333334)', '0.30000000000000004'];
        yield 'largest finite' => ['(fp #b0 #b11111111110 #xfffffffffffff)', '1.7976931348623157E+308'];
    }

    #[DataProvider('floats')]
    public function testFloats(string $smt, string $expected): void
    {
        self::assertSame($expected, $this->formatter->format(Sort::float(), SExpr::parse($smt)[0]));
    }

    /**
     * @return iterable<string, array{string, string}>
     */
    public static function strings(): iterable
    {
        yield 'empty' => ['""', "''"];
        yield 'printable' => ['"abc"', "'abc'"];
        yield 'single quote is escaped for a PHP literal' => ['"it\'s"', "'it\\'s'"];
        yield 'doubled quote is one quote' => ['"a""b"', "'a\"b'"];
        yield 'a hidden file name' => ['".jpg"', "'.jpg'"];
        yield 'a non-ASCII byte makes it a double-quoted literal' => ['"\u{c2}\u{a3}"', '"\xC2\xA3"'];
        yield 'NUL byte' => ['"a\u{0}b"', '"a\x00b"'];
        yield 'dollar sign is escaped in a double-quoted literal' => ['"$\u{e9}"', '"\$\xE9"'];
    }

    #[DataProvider('strings')]
    public function testStringsArePrintedAsPhpLiterals(string $smt, string $expected): void
    {
        self::assertSame($expected, $this->formatter->format(Sort::string(), $smt));
    }

    public function testEnumCases(): void
    {
        $sort = Sort::enum(new EnumSort('App\\Currency', 'E_App_Currency', ['Eur' => 'EUR', 'Usd' => 'USD'], 'string'));

        self::assertSame('Currency::Usd', $this->formatter->format($sort, 'C_App_Currency_Usd'));
    }

    public function testNullableValues(): void
    {
        $sort = Sort::nullable(Sort::int());

        self::assertSame('null', $this->formatter->format($sort, 'none_Opt_Int'));
        self::assertSame('5', $this->formatter->format($sort, ['some_Opt_Int', '5']));
        self::assertSame('-5', $this->formatter->format($sort, ['some_Opt_Int', ['-', '5']]));
    }

    public function testRecordsAreNestedAndKeepTheirFieldNames(): void
    {
        $coupon = Sort::record(new RecordSort('App\\Coupon', 'R_App_Coupon', ['percent' => Sort::int(), 'minimum' => Sort::nullable(Sort::int())], ['percent', 'minimum'], null));
        $cart = Sort::record(new RecordSort('App\\Cart', 'R_App_Cart', ['coupon' => Sort::nullable($coupon), 'paid' => Sort::bool()], ['coupon', 'paid'], null));

        $value = SExpr::parse('(mk_R_App_Cart (some_Opt_R_App_Coupon (mk_R_App_Coupon 10 none_Opt_Int)) true)')[0];

        self::assertSame('Cart(coupon: Coupon(percent: 10, minimum: null), paid: true)', $this->formatter->format($cart, $value));
        self::assertSame('Cart(coupon: null, paid: false)', $this->formatter->format($cart, SExpr::parse('(mk_R_App_Cart none_Opt_R_App_Coupon false)')[0]));
    }
}
