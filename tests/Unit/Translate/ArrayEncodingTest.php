<?php

declare(strict_types=1);

namespace PhpstanContracts\Tests\Unit\Translate;

use LogicException;
use PhpstanContracts\Smt\ShapeSort;
use PhpstanContracts\Smt\Smt;
use PhpstanContracts\Smt\Sort;
use PhpstanContracts\Solver\ScriptRenderer;
use PhpstanContracts\Translate\DomainBuilder;
use PhpstanContracts\Translate\Encoding;
use PhpstanContracts\Translate\ProbePlanner;
use PhpstanContracts\Translate\VarSpec;
use PHPUnit\Framework\TestCase;

final class ArrayEncodingTest extends TestCase
{
    public function testFieldSortsAreDeclaredBeforeTheShapeThatUsesThem(): void
    {
        $nullable = Sort::nullable(Sort::string());
        $shape = Sort::shape(ShapeSort::of(['note' => $nullable, 'total' => Sort::int()]));
        $encoding = new Encoding();

        $encoding->registerSort($shape);
        $encoding->registerSort($shape);

        self::assertSame([$nullable->smt, $shape->smt], array_map(static fn (Sort $sort): string => $sort->smt, $encoding->datatypes()));
    }

    public function testTheElementSortIsDeclaredBeforeTheList(): void
    {
        $element = Sort::nullable(Sort::int());
        $encoding = new Encoding();

        $encoding->registerSort(Sort::list($element));

        self::assertSame(['Opt_Int', 'List_Opt_Int'], array_map(static fn (Sort $sort): string => $sort->smt, $encoding->datatypes()));
    }

    public function testTheDatatypeDeclarationsOfAShapeAndAList(): void
    {
        $shape = Sort::shape(ShapeSort::of(['total' => Sort::int(), 'label' => Sort::string()]));
        $encoding = new Encoding();
        $encoding->registerSort($shape);
        $encoding->registerSort(Sort::list(Sort::float()));
        $name = $shape->smt;

        self::assertSame([
            sprintf('(declare-datatypes ((%1$s 0)) (((mk_%1$s (%1$s_0 String) (%1$s_1 Int)))))', $name),
            '(declare-datatypes ((List_Float64 0)) (((mk_List_Float64 (List_Float64_len Int) (List_Float64_items (Array Int Float64))))))',
        ], (new ScriptRenderer())->declarations($encoding));
    }

    public function testAnEmptyListSortIsNeverDeclared(): void
    {
        $encoding = new Encoding();

        $encoding->registerSort(Sort::emptyList());

        self::assertSame([], $encoding->datatypes());
    }

    public function testAValueWhoseTypeHoldsNullCannotBeDeclared(): void
    {
        $this->expectException(LogicException::class);

        (new Encoding())->fresh('x', Sort::shape(ShapeSort::of(['note' => Sort::null()])));
    }

    public function testObservedTermsAreTheSymbolsWhenNothingHoldsAList(): void
    {
        $encoding = new Encoding();
        $encoding->declareInput('totals', Sort::shape(ShapeSort::of(['net' => Sort::int()])));
        $encoding->declareResult(Sort::int());

        self::assertSame(['totals@1', 'result@2'], $encoding->observedTerms());
    }

    public function testObservedTermsAddTheLengthAndTheFirstElementsOfEveryReachableList(): void
    {
        $list = Sort::list(Sort::int());
        $encoding = new Encoding();
        $encoding->declareInput('items', $list);
        $terms = $encoding->observedTerms();

        self::assertCount(1 + 1 + ProbePlanner::MAX_ELEMENTS, $terms);
        self::assertSame(['items@1', '(List_Int_len items@1)', '(select (List_Int_items items@1) 0)'], array_slice($terms, 0, 3));
        self::assertSame('(select (List_Int_items items@1) 9)', end($terms));
    }

    public function testProbesFollowShapeFieldsRecordsAndNullablesToTheLists(): void
    {
        $list = Sort::list(Sort::int());
        $shape = Sort::shape(ShapeSort::of(['amounts' => $list, 'total' => Sort::int()]));
        $planner = new ProbePlanner();

        $probes = $planner->probes('t@1', Sort::nullable($shape));
        $accessor = $shape->shape?->accessor('amounts');

        self::assertSame(sprintf('(List_Int_len (%s (val_%s t@1)))', $accessor, Sort::nullable($shape)->smt), $probes[0]);
        self::assertCount(1 + ProbePlanner::MAX_ELEMENTS, $probes);
        self::assertSame([], $planner->probes('x@1', Sort::int()));
        self::assertSame([], $planner->probes('s@1', Sort::shape(ShapeSort::of(['total' => Sort::int()]))));
    }

    public function testListsInsideTheElementsOfAListAreFollowedOneLevelDeepAndNoFurther(): void
    {
        $inner = Sort::list(Sort::int());
        $holder = Sort::shape(ShapeSort::of(['tags' => $inner]));
        $outer = Sort::list($holder);
        $planner = new ProbePlanner();

        $probes = $planner->probes('o@1', $outer);

        self::assertCount(1 + ProbePlanner::MAX_ELEMENTS * (1 + 1 + ProbePlanner::MAX_ELEMENTS), $probes);
        self::assertSame([], $planner->probes('o@1', Sort::list(Sort::list($holder)), 2));
    }

    public function testAShapeInputIsConstrainedFieldByField(): void
    {
        $shape = Sort::shape(ShapeSort::of(['net' => Sort::int(), 'note' => Sort::nullable(Sort::string())]), ['net', 'note']);
        $spec = new VarSpec($shape, fields: [
            'net' => new VarSpec(Sort::int(), 0, 10),
            'note' => new VarSpec(Sort::nullable(Sort::string()), inner: new VarSpec(Sort::string(), nonEmpty: true)),
        ]);
        $term = Smt::symbol('s@1', $shape);
        $name = $shape->smt;
        $domains = new DomainBuilder();

        self::assertSame(
            sprintf('(and (and (>= (%1$s_0 s@1) 0) (<= (%1$s_0 s@1) 10)) (or (= (%1$s_1 s@1) none_Opt_String) (and (str.in_re (val_Opt_String (%1$s_1 s@1)) (re.* (re.range "\u{0}" "\u{ff}"))) (> (str.len (val_Opt_String (%1$s_1 s@1))) 0))))', $name),
            $domains->constraint($spec, $term, true)->smt,
        );
    }

    public function testAShapeResultOnlyChecksTheFieldsThatHaveARange(): void
    {
        $shape = Sort::shape(ShapeSort::of(['net' => Sort::int(), 'vat' => Sort::int()]), ['net', 'vat']);
        $spec = new VarSpec($shape, fields: ['net' => new VarSpec(Sort::int(), 0, 10), 'vat' => new VarSpec(Sort::int())]);
        $term = Smt::symbol('r@1', $shape);

        self::assertSame(
            sprintf('(and (>= (%1$s_0 r@1) 0) (<= (%1$s_0 r@1) 10))', $shape->smt),
            (new DomainBuilder())->constraint($spec, $term, false)->smt,
        );
    }
}
