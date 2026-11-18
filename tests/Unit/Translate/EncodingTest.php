<?php

declare(strict_types=1);

namespace PhpstanContracts\Tests\Unit\Translate;

use LogicException;
use PhpstanContracts\Smt\EnumSort;
use PhpstanContracts\Smt\RecordSort;
use PhpstanContracts\Smt\Smt;
use PhpstanContracts\Smt\Sort;
use PhpstanContracts\Translate\Encoding;
use PhpstanContracts\Translate\Obligation;
use PhpstanContracts\Translate\ObligationKind;
use PHPUnit\Framework\TestCase;

final class EncodingTest extends TestCase
{
    public function testEveryDeclaredNameIsUnique(): void
    {
        $encoding = new Encoding();

        $first = $encoding->declareInput('price', Sort::int());
        $second = $encoding->declareInput('price', Sort::int());
        $third = $encoding->fresh('price', Sort::int());

        self::assertSame('price@1', $first->smt);
        self::assertSame('price@2', $second->smt);
        self::assertSame('price@3', $third->smt);
    }

    public function testPhpNamesAreMadeSafeForSmt(): void
    {
        $term = (new Encoding())->fresh('a-b é', Sort::int());

        self::assertMatchesRegularExpression('/^[A-Za-z0-9_]+@\d+$/', $term->smt);
    }

    public function testDefiningAnAtomReusesIt(): void
    {
        $encoding = new Encoding();

        self::assertSame('5', $encoding->define('x', Smt::int(5))->smt);
        self::assertSame([], $encoding->commands(), 'no constant is declared for a literal');
    }

    public function testDefiningAnExpressionDeclaresAConstantEqualToIt(): void
    {
        $encoding = new Encoding();
        $a = $encoding->declareInput('a', Sort::int());

        $defined = $encoding->define('t', Smt::arithmetic('+', $a, Smt::int(1)));

        self::assertSame('t@2', $defined->smt);
        self::assertSame(
            ['(declare-const a@1 Int)', '(declare-const t@2 Int)', '(assert (= t@2 (+ a@1 1)))'],
            $encoding->commands(),
        );
    }

    public function testATriviallyTrueAssertionIsNotEmitted(): void
    {
        $encoding = new Encoding();
        $encoding->assert(Smt::bool(true));

        self::assertSame([], $encoding->commands());
    }

    public function testObligationsThatCannotFailAreDropped(): void
    {
        $encoding = new Encoding();

        $encoding->addObligation(new Obligation(ObligationKind::DivisionByZero, 3, 'x', Smt::bool(true), Smt::bool(true)));
        $encoding->addObligation(new Obligation(ObligationKind::Overflow, 4, 'x', Smt::bool(false), Smt::symbol('g', Sort::bool())));
        $encoding->addObligation(new Obligation(ObligationKind::Overflow, 5, 'x', Smt::bool(true), Smt::symbol('g', Sort::bool())));

        self::assertCount(1, $encoding->obligations, 'a goal that is true, and an unreachable path, need no check');
        self::assertSame(5, $encoding->obligations[0]->line);
    }

    public function testDatatypesAreDeclaredAfterTheSortsTheyDependOn(): void
    {
        $enum = Sort::enum(new EnumSort('App\\Currency', 'E_App_Currency', ['Eur' => 'EUR'], 'string'));
        $coupon = Sort::record(new RecordSort('App\\Coupon', 'R_App_Coupon', ['minimum' => Sort::nullable(Sort::int())], ['minimum'], null));
        $cart = Sort::record(new RecordSort('App\\Cart', 'R_App_Cart', ['coupon' => Sort::nullable($coupon), 'currency' => $enum], ['coupon', 'currency'], null));

        $encoding = new Encoding();
        $encoding->declareInput('cart', $cart);

        self::assertSame(
            ['Opt_Int', 'R_App_Coupon', 'Opt_R_App_Coupon', 'E_App_Currency', 'R_App_Cart'],
            array_map(static fn (Sort $sort): string => $sort->smt, $encoding->datatypes()),
        );
    }

    public function testASortIsDeclaredOnce(): void
    {
        $encoding = new Encoding();
        $nullable = Sort::nullable(Sort::int());

        $encoding->declareInput('a', $nullable);
        $encoding->declareInput('b', $nullable);

        self::assertCount(1, $encoding->datatypes());
    }

    public function testScalarSortsNeedNoDeclaration(): void
    {
        $encoding = new Encoding();
        $encoding->declareInput('a', Sort::int());
        $encoding->declareInput('b', Sort::float());
        $encoding->declareInput('c', Sort::string());
        $encoding->declareInput('d', Sort::bool());

        self::assertSame([], $encoding->datatypes());
    }

    public function testReturnsAreRecordedWithAMarkerForTheCounterexample(): void
    {
        $encoding = new Encoding();
        $x = $encoding->declareInput('x', Sort::int());
        $encoding->declareResult(Sort::int());
        $condition = $encoding->define('cond', Smt::compare('>', $x, Smt::int(0)));

        $encoding->recordReturn($condition, $x, 12);

        self::assertCount(1, $encoding->returnMarkers);
        self::assertSame(12, $encoding->returnMarkers[0]->line);
        self::assertSame('(or (not cond@3) (= result@2 x@1))', substr($encoding->commands()[4], 8, -1));
        self::assertSame('cond@3', $encoding->anyReturn()->smt);
    }

    public function testTheConditionOfSeveralReturnsIsTheirDisjunction(): void
    {
        $encoding = new Encoding();
        $encoding->declareResult(Sort::int());
        $a = $encoding->fresh('a', Sort::bool());
        $b = $encoding->fresh('b', Sort::bool());

        $encoding->recordReturn($a, Smt::int(1), 3);
        $encoding->recordReturn($b, Smt::int(2), 5);

        self::assertSame('(or a@2 b@3)', $encoding->anyReturn()->smt);
    }

    public function testAReturnNeedsAResultToBeDeclaredFirst(): void
    {
        $this->expectException(LogicException::class);

        (new Encoding())->recordReturn(Smt::bool(true), Smt::int(1), 1);
    }

    public function testSymbolsReadBackFromACounterexample(): void
    {
        $encoding = new Encoding();
        $encoding->declareInput('a', Sort::int());
        $encoding->declareResult(Sort::bool());

        self::assertSame(
            ['a@1', 'result@2'],
            array_map(static fn ($symbol): string => $symbol->smtName, $encoding->observedSymbols()),
        );
    }
}
