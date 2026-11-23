<?php

declare(strict_types=1);

namespace PhpstanContracts\Tests\Unit\Translate;

use PhpstanContracts\Smt\EnumSort;
use PhpstanContracts\Smt\RecordSort;
use PhpstanContracts\Smt\Smt;
use PhpstanContracts\Smt\Sort;
use PhpstanContracts\Smt\Term;
use PhpstanContracts\Translate\DomainBuilder;
use PhpstanContracts\Translate\VarSpec;
use PHPUnit\Framework\TestCase;

final class DomainBuilderTest extends TestCase
{
    private DomainBuilder $domains;

    protected function setUp(): void
    {
        $this->domains = new DomainBuilder();
    }

    public function testIntegerRangeIsAnAssumptionOnInputs(): void
    {
        $x = Smt::symbol('x', Sort::int());
        $spec = new VarSpec(Sort::int(), 1, 365);

        self::assertSame('(and (>= x 1) (<= x 365))', $this->domains->constraint($spec, $x, true)->smt);
    }

    public function testAnUnboundedIntegerInputIsStillLimitedToTheSixtyFourBitRange(): void
    {
        $x = Smt::symbol('x', Sort::int());

        self::assertSame(
            '(and (>= x (- 9223372036854775808)) (<= x 9223372036854775807))',
            $this->domains->constraint(new VarSpec(Sort::int()), $x, true)->smt,
        );
    }

    public function testAnUnboundedIntegerResultHasNothingToCheck(): void
    {
        $x = Smt::symbol('x', Sort::int());

        self::assertTrue($this->domains->constraint(new VarSpec(Sort::int()), $x, false)->isTrue());
        self::assertSame('(and (>= x 0) (<= x 10))', $this->domains->constraint(new VarSpec(Sort::int(), 0, 10), $x, false)->smt);
    }

    public function testHalfOpenRangesUseTheSixtyFourBitLimitOnTheOpenSide(): void
    {
        $x = Smt::symbol('x', Sort::int());

        self::assertSame(
            '(and (>= x 1) (<= x 9223372036854775807))',
            $this->domains->constraint(new VarSpec(Sort::int(), 1, null), $x, true)->smt,
        );
    }

    public function testStringsAreBytesOnInputsOnly(): void
    {
        $s = Smt::symbol('s', Sort::string());
        $spec = new VarSpec(Sort::string());

        self::assertSame('(str.in_re s (re.* (re.range "\u{0}" "\u{ff}")))', $this->domains->constraint($spec, $s, true)->smt);
        self::assertTrue($this->domains->constraint($spec, $s, false)->isTrue());
    }

    public function testNonEmptyStringsAreChecked(): void
    {
        $s = Smt::symbol('s', Sort::string());
        $spec = new VarSpec(Sort::string(), nonEmpty: true);

        self::assertSame('(> (str.len s) 0)', $this->domains->constraint($spec, $s, false)->smt);
        self::assertStringContainsString('(> (str.len s) 0)', $this->domains->constraint($spec, $s, true)->smt);
    }

    public function testScalarsWithoutARangeAreUnconstrained(): void
    {
        self::assertTrue($this->domains->constraint(new VarSpec(Sort::bool()), Smt::symbol('b', Sort::bool()), true)->isTrue());
        self::assertTrue($this->domains->constraint(new VarSpec(Sort::float()), Smt::symbol('f', Sort::float()), true)->isTrue());

        $enum = Sort::enum(new EnumSort('App\\E', 'E_App_E', ['A' => null], null));
        self::assertTrue($this->domains->constraint(new VarSpec($enum), Smt::symbol('e', $enum), true)->isTrue());
    }

    public function testNullableValuesAreConstrainedOnlyWhenPresent(): void
    {
        $sort = Sort::nullable(Sort::int());
        $x = Smt::symbol('x', $sort);
        $spec = new VarSpec($sort, inner: new VarSpec(Sort::int(), 0, 5));

        self::assertSame(
            '(or (= x none_Opt_Int) (and (>= (val_Opt_Int x) 0) (<= (val_Opt_Int x) 5)))',
            $this->domains->constraint($spec, $x, true)->smt,
        );
    }

    public function testRecordsConstrainEachFieldThroughItsAccessor(): void
    {
        $record = new RecordSort('App\\Box', 'R_App_Box', ['size' => Sort::int(), 'open' => Sort::bool()], ['size', 'open'], null);
        $sort = Sort::record($record);
        $box = Smt::symbol('b', $sort);
        $spec = new VarSpec($sort, fields: [
            'size' => new VarSpec(Sort::int(), 1, 9),
            'open' => new VarSpec(Sort::bool()),
        ]);

        self::assertSame(
            '(and (>= (R_App_Box/size b) 1) (<= (R_App_Box/size b) 9))',
            $this->domains->constraint($spec, $box, true)->smt,
        );
    }

    public function testNestedRecordsAndNullableFieldsCompose(): void
    {
        $inner = new RecordSort('App\\Inner', 'R_App_Inner', ['n' => Sort::int()], ['n'], null);
        $innerSort = Sort::record($inner);
        $outer = new RecordSort('App\\Outer', 'R_App_Outer', ['inner' => Sort::nullable($innerSort)], ['inner'], null);
        $outerSort = Sort::record($outer);

        $spec = new VarSpec($outerSort, fields: [
            'inner' => new VarSpec(Sort::nullable($innerSort), inner: new VarSpec($innerSort, fields: ['n' => new VarSpec(Sort::int(), 0, 1)])),
        ]);

        $constraint = $this->domains->constraint($spec, Smt::symbol('o', $outerSort), true);

        self::assertInstanceOf(Term::class, $constraint);
        self::assertSame(
            '(or (= (R_App_Outer/inner o) none_Opt_R_App_Inner) (and (>= (R_App_Inner/n (val_Opt_R_App_Inner (R_App_Outer/inner o))) 0) (<= (R_App_Inner/n (val_Opt_R_App_Inner (R_App_Outer/inner o))) 1)))',
            $constraint->smt,
        );
    }

    public function testAListInputHasABoundedLengthAndElementsInTheirDomain(): void
    {
        $list = Sort::list(Sort::int());
        $l = Smt::symbol('l', $list);
        $spec = new VarSpec($list, element: new VarSpec(Sort::int(), 0, 100));

        self::assertSame(
            '(and (and (>= (List_Int_len l) 0) (<= (List_Int_len l) 1073741824)) '
            . '(forall ((k!0 Int)) (! (or (not (and (<= 0 k!0) (< k!0 (List_Int_len l)))) (and (>= (select (List_Int_items l) k!0) 0) (<= (select (List_Int_items l) k!0) 100))) :pattern ((select (List_Int_items l) k!0)))))',
            $this->domains->constraint($spec, $l, true)->smt,
        );
    }

    public function testANonEmptyListNeedsAnElement(): void
    {
        $list = Sort::list(Sort::bool());
        $l = Smt::symbol('l', $list);
        $spec = new VarSpec($list, nonEmpty: true, element: new VarSpec(Sort::bool()));

        self::assertSame('(>= (List_Bool_len l) 1)', $this->domains->constraint($spec, $l, false)->smt, 'a returned list has no bound to prove for a trivial element');
        self::assertSame(
            '(and (and (>= (List_Bool_len l) 0) (<= (List_Bool_len l) 1073741824)) (>= (List_Bool_len l) 1))',
            $this->domains->constraint($spec, $l, true)->smt,
        );
    }

    public function testAReturnedListIsOnlyCheckedWhereItsElementsHaveADomain(): void
    {
        $list = Sort::list(Sort::int());
        $l = Smt::symbol('l', $list);

        self::assertTrue($this->domains->constraint(new VarSpec($list, element: new VarSpec(Sort::int())), $l, false)->isTrue());
        self::assertStringStartsWith('(forall ((k!0 Int))', $this->domains->constraint(new VarSpec($list, element: new VarSpec(Sort::int(), 0, 100)), $l, false)->smt);
    }

    public function testTheBoundVariableOfANestedListHasAnotherName(): void
    {
        $inner = Sort::list(Sort::int());
        $shape = \PhpstanContracts\Smt\ShapeSort::of(['items' => $inner]);
        $outer = Sort::list(Sort::shape($shape));
        $l = Smt::symbol('l', $outer);
        $spec = new VarSpec(
            $outer,
            element: new VarSpec(Sort::shape($shape), fields: ['items' => new VarSpec($inner, element: new VarSpec(Sort::int(), 0, 9))]),
        );

        $smt = $this->domains->constraint($spec, $l, false)->smt;
        self::assertStringContainsString('(forall ((k!0 Int))', $smt);
        self::assertStringContainsString('(forall ((k!1 Int))', $smt);
        self::assertSame(2, substr_count($smt, 'forall'));
    }
}
