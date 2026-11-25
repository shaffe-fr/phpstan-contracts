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
use PhpstanContracts\Translate\VarSpec;
use PHPUnit\Framework\TestCase;

final class EncodingTest extends TestCase
{
    public function testTheSplitLengthIsTheOneOfTheWidestOutermostLoop(): void
    {
        $encoding = new Encoding();
        self::assertNull($encoding->splitLength());

        $encoding->noteSplitLength('(List_Int_len a@1)', 4, '$a');
        $encoding->noteSplitLength('(List_Int_len b@2)', 7, '$b');
        $encoding->noteSplitLength('(List_Int_len c@3)', 7, '$c');
        $encoding->noteSplitLength('(List_Int_len d@4)', 2, '$d');

        self::assertSame(['(List_Int_len b@2)', 7, '$b'], $encoding->splitLength(), 'the largest bound, the first of equals');
    }

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

    public function testTheRangeLemmaOfArraySumIsOnlyAssertedForBoundedInputListsOnceTheSumIsUsed(): void
    {
        $encoding = new Encoding();
        $bounded = $encoding->declareInput('bounded', Sort::list(Sort::int()));
        $encoding->registerInput($bounded, new VarSpec(Sort::list(Sort::int()), element: new VarSpec(Sort::int(), 0, 10)));
        $half = $encoding->declareInput('half', Sort::list(Sort::int()));
        $encoding->registerInput($half, new VarSpec(Sort::list(Sort::int()), element: new VarSpec(Sort::int(), 0, null)));
        $before = $encoding->commands();

        self::assertFalse($encoding->listSumRequired());
        $encoding->requireListSum();
        $encoding->requireListSum();

        self::assertTrue($encoding->listSumRequired());
        self::assertTrue($encoding->needsSolverFallback);
        $lemmas = array_values(array_diff($encoding->commands(), $before));
        self::assertCount(1, $lemmas, 'one lemma, for the list whose elements have both bounds');
        self::assertStringContainsString('(sum_List_Int (List_Int_items bounded@1) n!0)', $lemmas[0]);
        self::assertStringContainsString('(<= (* 0 n!0) ', $lemmas[0]);
        self::assertStringContainsString('(* 10 n!0))', $lemmas[0]);
        self::assertStringNotContainsString('half@2', $lemmas[0]);
    }

    public function testTwoClassesCannotShareASort(): void
    {
        $encoding = new Encoding();
        $encoding->registerSort(Sort::enum(new EnumSort('App\\A', 'E_App_A', ['X' => null], null)));

        $this->expectException(LogicException::class);
        $this->expectExceptionMessage('Two classes share the SMT sort E_App_A.');

        $encoding->registerSort(Sort::enum(new EnumSort('App\\B', 'E_App_A', ['X' => null], null)));
    }
}
