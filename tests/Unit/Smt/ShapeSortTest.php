<?php

declare(strict_types=1);

namespace PhpstanContracts\Tests\Unit\Smt;

use LogicException;
use PhpstanContracts\Smt\ShapeSort;
use PhpstanContracts\Smt\Smt;
use PhpstanContracts\Smt\Sort;
use PhpstanContracts\Smt\SortKind;
use PhpstanContracts\Smt\Term;
use PHPUnit\Framework\TestCase;

final class ShapeSortTest extends TestCase
{
    public function testTheKeysAreSortedByByteOrderWhateverTheOrderTheyAreWrittenIn(): void
    {
        $written = ShapeSort::of(['vat' => Sort::int(), 'Net' => Sort::int(), 'net' => Sort::string()]);

        self::assertSame(['Net', 'net', 'vat'], $written->keys());
    }

    public function testTwoShapesWithTheSameKeysAndSortsAreTheSameSort(): void
    {
        $first = Sort::shape(ShapeSort::of(['net' => Sort::int(), 'vat' => Sort::int()]), ['net', 'vat']);
        $second = Sort::shape(ShapeSort::of(['vat' => Sort::int(), 'net' => Sort::int()]), ['vat', 'net']);

        self::assertTrue($first->equals($second));
        self::assertSame($first->smt, $second->smt);
        self::assertMatchesRegularExpression('/^Shape_[0-9a-f]{10}$/', $first->smt);
    }

    public function testAnotherKeyOrAnotherFieldSortIsAnotherSort(): void
    {
        $base = Sort::shape(ShapeSort::of(['net' => Sort::int()]));

        self::assertFalse($base->equals(Sort::shape(ShapeSort::of(['vat' => Sort::int()]))));
        self::assertFalse($base->equals(Sort::shape(ShapeSort::of(['net' => Sort::float()]))));
        self::assertFalse($base->equals(Sort::shape(ShapeSort::of(['net' => Sort::int(), 'vat' => Sort::int()]))));
    }

    public function testAKeyThatLooksLikeASeparatorDoesNotCollideWithTwoKeys(): void
    {
        $one = ShapeSort::of(['a:Int;b' => Sort::int()]);
        $two = ShapeSort::of(['a' => Sort::int(), 'b' => Sort::int()]);

        self::assertNotSame($one->smtName, $two->smtName);
    }

    public function testAccessorsArePositionalSoThatAnyKeyIsSafe(): void
    {
        $shape = ShapeSort::of(['unit price' => Sort::int(), 'a-b' => Sort::int()]);

        self::assertSame($shape->smtName . '_0', $shape->accessor('a-b'));
        self::assertSame($shape->smtName . '_1', $shape->accessor('unit price'));
        self::assertSame('mk_' . $shape->smtName, $shape->constructorName());
    }

    public function testTheDescriptionFollowsTheDeclaredOrderAndQuotesOddKeys(): void
    {
        $sort = Sort::shape(ShapeSort::of(['total' => Sort::int(), 'label' => Sort::string(), 'unit price' => Sort::float()]), ['total', 'label', 'unit price']);

        self::assertSame("array{total: int, label: string, 'unit price': float}", $sort->describe());
        self::assertSame("array{'unit price': float, label: string, total: int}", ($sort->shape ?? throw new LogicException('A shape sort is needed.'))->describe(['unit price', 'label', 'total']));
        self::assertSame('array{label: string, total: int, \'unit price\': float}', ($sort->shape ?? throw new LogicException('A shape sort is needed.'))->describe());
    }

    public function testASortKnowsWhetherItIsAnArrayOrHoldsNull(): void
    {
        $shape = Sort::shape(ShapeSort::of(['note' => Sort::null()]));

        self::assertSame(SortKind::Shape, $shape->kind);
        self::assertTrue($shape->isArrayLike());
        self::assertTrue(Sort::nullable(Sort::shape(ShapeSort::of(['a' => Sort::int()])))->isArrayLike());
        self::assertFalse(Sort::int()->isArrayLike());
        self::assertTrue($shape->containsNull());
        self::assertFalse(Sort::shape(ShapeSort::of(['a' => Sort::nullable(Sort::int())]))->containsNull());
        self::assertSame('array{}', Sort::emptyList()->describe());
        self::assertSame('list<?int>', Sort::list(Sort::nullable(Sort::int()))->describe());
        self::assertSame('List_Opt_Int', Sort::list(Sort::nullable(Sort::int()))->smt);
    }

    public function testAShapeTermIsBuiltInCanonicalOrderAndReadThroughPositionalAccessors(): void
    {
        $sort = Sort::shape(ShapeSort::of(['vat' => Sort::int(), 'net' => Sort::int()]), ['vat', 'net']);
        $name = $sort->smt;
        $literal = Smt::shape($sort, [Smt::int(1), Smt::int(2)]);

        self::assertSame(sprintf('(mk_%s 1 2)', $name), $literal->smt);
        // A literal knows its parts, so reading it is the part itself.
        self::assertSame('1', Smt::shapeField($literal, 'net')->smt);
        self::assertSame('2', Smt::shapeField($literal, 'vat')->smt);

        $variable = Smt::symbol('totals@1', $sort);
        self::assertSame(sprintf('(%s_0 totals@1)', $name), Smt::shapeField($variable, 'net')->smt);
        self::assertSame(sprintf('(%s_1 totals@1)', $name), Smt::shapeField($variable, 'vat')->smt);
        self::assertTrue(Smt::shapeField($variable, 'net')->sort->isInt());
    }

    public function testReadingAKeyThatTheShapeDoesNotHaveIsAProgrammingError(): void
    {
        $this->expectException(LogicException::class);

        Smt::shapeField(new Term('x', Sort::shape(ShapeSort::of(['net' => Sort::int()]))), 'gross');
    }

    public function testArraysHaveNoPhpEqualityInTheTermBuilders(): void
    {
        $shape = Smt::symbol('s', Sort::shape(ShapeSort::of(['net' => Sort::int()])));

        $this->expectException(LogicException::class);

        Smt::phpEquals($shape, $shape);
    }
}
