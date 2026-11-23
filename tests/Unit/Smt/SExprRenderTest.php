<?php

declare(strict_types=1);

namespace PhpstanContracts\Tests\Unit\Smt;

use PhpstanContracts\Smt\SExpr;
use PhpstanContracts\Solver\Z3Runner;
use PhpstanContracts\Tests\Support\RequiresZ3;
use PHPUnit\Framework\TestCase;

final class SExprRenderTest extends TestCase
{
    use RequiresZ3;

    public function testRenderWritesTermsTheWayTheTermBuildersDo(): void
    {
        foreach (['x', '5', '(- 7)', '(select (List_Int_items l@1) 0)', '(Shape_ab12_0 (val_Opt_Shape_ab12 x@2))', '"a b"'] as $term) {
            self::assertSame($term, SExpr::render(SExpr::parse($term)[0]));
        }
    }

    public function testRenderIgnoresTheWhitespaceOfTheAnswer(): void
    {
        self::assertSame('(select (a b) 0)', SExpr::render(SExpr::parse("(select\n   (a   b)\t0)")[0]));
    }

    public function testZ3AnswersAProbeTermVerbatimSoItCanBeFoundAgain(): void
    {
        $script = <<<'SMT'
            (set-option :produce-models true)
            (declare-datatypes ((List_Int 0)) (((mk_List_Int (List_Int_len Int) (List_Int_items (Array Int Int))))))
            (declare-const l@1 List_Int)
            (assert (= (List_Int_len l@1) 2))
            (assert (= (select (List_Int_items l@1) 0) (- 7)))
            (echo "@@0")
            (check-sat)
            (get-value (l@1 (List_Int_len l@1) (select (List_Int_items l@1) 0)))
            SMT;

        $results = (new Z3Runner($this->requireZ3()))->run($script . "\n", 1);

        $values = $results[0]->values;
        self::assertSame(['-', '7'], $values['(select (List_Int_items l@1) 0)']);
        self::assertSame('2', $values['(List_Int_len l@1)']);
        self::assertArrayHasKey('l@1', $values);
    }
}
