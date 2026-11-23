<?php

declare(strict_types=1);

namespace PhpstanContracts\Tests\Unit\Report;

use PhpstanContracts\Report\ValueFormatter;
use PhpstanContracts\Smt\ShapeSort;
use PhpstanContracts\Smt\SExpr;
use PhpstanContracts\Smt\Sort;
use PHPUnit\Framework\TestCase;

final class ArrayValueFormatterTest extends TestCase
{
    private ValueFormatter $formatter;

    protected function setUp(): void
    {
        $this->formatter = new ValueFormatter();
    }

    public function testAShapeIsPrintedInTheOrderOfTheDeclaredType(): void
    {
        // The datatype lays the fields out by key, the type was written as total, label.
        $sort = Sort::shape(ShapeSort::of(['total' => Sort::int(), 'label' => Sort::string()]), ['total', 'label']);
        $raw = SExpr::parse(sprintf('(mk_%s "x" 1)', $sort->smt))[0];

        self::assertSame("['total' => 1, 'label' => 'x']", $this->formatter->format($sort, $raw));
    }

    public function testShapeFieldsAreFormattedByTheirOwnSort(): void
    {
        $nullable = Sort::nullable(Sort::float());
        $sort = Sort::shape(ShapeSort::of(['ratio' => $nullable, 'ok' => Sort::bool(), 'delta' => Sort::int()]), ['ratio', 'ok', 'delta']);
        $raw = SExpr::parse(sprintf('(mk_%s (- 3) false (some_%s (_ -zero 11 53)))', $sort->smt, $nullable->smt))[0];

        self::assertSame("['ratio' => -0.0, 'ok' => false, 'delta' => -3]", $this->formatter->format($sort, $raw));
    }

    public function testANullFieldAndAnOddKey(): void
    {
        $nullable = Sort::nullable(Sort::int());
        $sort = Sort::shape(ShapeSort::of(['unit price' => $nullable]), ['unit price']);
        $raw = SExpr::parse(sprintf('(mk_%s none_%s)', $sort->smt, $nullable->smt))[0];

        self::assertSame("['unit price' => null]", $this->formatter->format($sort, $raw));
    }

    public function testAShapeWithoutADeclaredOrderIsPrintedInCanonicalOrder(): void
    {
        $sort = Sort::shape(ShapeSort::of(['vat' => Sort::int(), 'net' => Sort::int()]));
        $raw = SExpr::parse(sprintf('(mk_%s 1 2)', $sort->smt))[0];

        self::assertSame("['net' => 1, 'vat' => 2]", $this->formatter->format($sort, $raw));
    }

    public function testAListIsPrintedFromItsProbes(): void
    {
        $sort = Sort::list(Sort::int());
        $raw = SExpr::parse('(mk_List_Int 2 ((as const (Array Int Int)) 51))')[0];
        $probes = [
            '(select (List_Int_items l@1) 0)' => '51',
            '(select (List_Int_items l@1) 1)' => ['-', '3'],
        ];

        self::assertSame('[51, -3]', $this->formatter->format($sort, $raw, 'l@1', $probes));
    }

    public function testAnEmptyListAndAListWithoutProbes(): void
    {
        $sort = Sort::list(Sort::int());
        $empty = SExpr::parse('(mk_List_Int 0 ((as const (Array Int Int)) 0))')[0];
        $two = SExpr::parse('(mk_List_Int 2 ((as const (Array Int Int)) 0))')[0];

        self::assertSame('[]', $this->formatter->format($sort, $empty, 'l@1', []));
        self::assertSame('[]', $this->formatter->format($sort, $empty));
        self::assertSame('[any, any]', $this->formatter->format($sort, $two, 'l@1', []));
        self::assertSame('list<int>', $this->formatter->format($sort, $two));
    }

    public function testFloatsAndNullsInAList(): void
    {
        $element = Sort::nullable(Sort::float());
        $sort = Sort::list($element);
        $raw = SExpr::parse('(mk_List_Opt_Float64 3 x)')[0];
        $probes = [
            '(select (List_Opt_Float64_items l@1) 0)' => SExpr::parse('(some_Opt_Float64 (_ NaN 11 53))')[0],
            '(select (List_Opt_Float64_items l@1) 1)' => SExpr::parse('(some_Opt_Float64 (_ -zero 11 53))')[0],
            '(select (List_Opt_Float64_items l@1) 2)' => 'none_Opt_Float64',
        ];

        self::assertSame('[NAN, -0.0, null]', $this->formatter->format($sort, $raw, 'l@1', $probes));
    }

    public function testAListLongerThanTheProbesIsDescribedInsteadOfPrinted(): void
    {
        $sort = Sort::list(Sort::int());
        $raw = SExpr::parse('(mk_List_Int 28224 ((as const (Array Int Int)) 3))')[0];
        $probes = [];
        for ($index = 0; $index < 10; ++$index) {
            $probes[sprintf('(select (List_Int_items l@1) %d)', $index)] = '3';
        }

        self::assertSame(
            'a list of 28224 elements starting with [3, 3, 3, 3, 3, 3, 3, 3, 3, 3]',
            $this->formatter->format($sort, $raw, 'l@1', $probes),
        );
    }

    public function testAListInsideAShapeUsesTheProbeOfItsAccessor(): void
    {
        $list = Sort::list(Sort::string());
        $sort = Sort::shape(ShapeSort::of(['tags' => $list, 'n' => Sort::int()]), ['n', 'tags']);
        $raw = SExpr::parse(sprintf('(mk_%s 1 (mk_List_String 1 x))', $sort->smt))[0];
        $probes = [sprintf('(select (List_String_items (%s_1 t@1)) 0)', $sort->smt) => '"EUR"'];

        self::assertSame("['n' => 1, 'tags' => ['EUR']]", $this->formatter->format($sort, $raw, 't@1', $probes));
    }

    public function testAnArrayThatZ3SharesWithALetIsResolvedBeforeItIsRead(): void
    {
        $sort = Sort::list(Sort::int());
        $raw = SExpr::parse('(let ((a!1 (store ((as const (Array Int Int)) 3) 0 1))) (mk_List_Int 1 a!1))')[0];
        $probes = [];
        for ($index = 0; $index < 10; ++$index) {
            $probes[sprintf('(select (List_Int_items l@1) %d)', $index)] = $index === 0 ? '1' : '3';
        }

        self::assertSame('[1]', $this->formatter->format($sort, $raw, 'l@1', $probes));
    }

    public function testASharedArrayInsideAShapeIsResolved(): void
    {
        $list = Sort::list(Sort::int());
        $sort = Sort::shape(ShapeSort::of(['items' => $list, 'n' => Sort::int()]), ['n', 'items']);
        $raw = SExpr::parse(sprintf('(let ((a!1 (store ((as const (Array Int Int)) 0) 0 5))) (mk_%s (mk_List_Int 1 a!1) 2))', $sort->smt))[0];
        $probes = [sprintf('(select (List_Int_items (%s_0 t@1)) 0)', $sort->smt) => '5'];

        self::assertSame("['n' => 2, 'items' => [5]]", $this->formatter->format($sort, $raw, 't@1', $probes));
    }

    public function testAnEmptyListLiteralHasNoValueToPrint(): void
    {
        self::assertSame('[]', $this->formatter->format(Sort::emptyList(), 'x'));
    }
}
