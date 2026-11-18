<?php

declare(strict_types=1);

namespace PhpstanContracts\Tests\Unit\Smt;

use PhpstanContracts\Smt\SExpr;
use PHPUnit\Framework\TestCase;
use RuntimeException;

final class SExprTest extends TestCase
{
    public function testAtomsAndNestedLists(): void
    {
        self::assertSame(
            [[['x', '5'], ['y', ['-', '3']]]],
            SExpr::parse('((x 5) (y (- 3)))'),
        );
    }

    public function testGetValueAnswer(): void
    {
        self::assertSame(
            [[['a@1', '49840'], ['b@2', ['-', '7']], ['c@3', 'true']]],
            SExpr::parse("((a@1 49840)\n (b@2 (- 7))\n (c@3 true))"),
        );
    }

    public function testSeveralTopLevelExpressions(): void
    {
        self::assertSame(['sat', ['x', '1']], SExpr::parse("sat\n(x 1)"));
    }

    public function testStringLiteralsKeepTheirQuotes(): void
    {
        self::assertSame([[['s', '"abc"']]], SExpr::parse('((s "abc"))'));
    }

    public function testDoubledQuoteInsideAStringIsNotTheEndOfTheString(): void
    {
        $parsed = SExpr::parse('((s "a""b\u{0}\u{e9}"))');

        self::assertSame('"a""b\u{0}\u{e9}"', $parsed[0][0][1]);
    }

    public function testParenthesesInsideAStringAreText(): void
    {
        $parsed = SExpr::parse('((s ")(")) (t 1)');

        self::assertSame('")("', $parsed[0][0][1]);
        self::assertSame(['t', '1'], $parsed[1]);
    }

    public function testQuotedSymbolsLoseTheirPipes(): void
    {
        self::assertSame([['odd name', '1']], SExpr::parse('(|odd name| 1)'));
    }

    public function testFloatingPointValues(): void
    {
        self::assertSame(
            [['fp', '#b0', '#b01111111011', '#x999999999999a']],
            SExpr::parse('(fp #b0 #b01111111011 #x999999999999a)'),
        );
        self::assertSame([['_', 'NaN', '11', '53']], SExpr::parse('(_ NaN 11 53)'));
    }

    public function testErrorLinesFromTheSolverAreReadAsLists(): void
    {
        $parsed = SExpr::parse('(error "line 38 column 49: model is not available")');

        self::assertSame('error', $parsed[0][0]);
    }

    public function testUnbalancedClosingParenthesis(): void
    {
        $this->expectException(RuntimeException::class);

        SExpr::parse('(a))');
    }

    public function testUnclosedParenthesis(): void
    {
        $this->expectException(RuntimeException::class);

        SExpr::parse('((a b)');
    }
}
