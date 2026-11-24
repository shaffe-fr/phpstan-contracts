<?php

declare(strict_types=1);

namespace PhpstanContracts\Tests\Unit\Translate;

use InvalidArgumentException;
use PhpstanContracts\Contract\ContractClause;
use PhpstanContracts\Translate\LoopBounds;
use PhpParser\Node\Expr;
use PhpParser\Node\Stmt;
use PhpParser\ParserFactory;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class LoopBoundsTest extends TestCase
{
    /**
     * @return iterable<string, array{list<string>, string, int, string|null}>
     */
    public static function boundCases(): iterable
    {
        yield 'at most' => [['count($lines) <= 50'], '$lines', 50, 'count($lines) <= 50'];
        yield 'less than' => [['count($lines) < 50'], '$lines', 49, 'count($lines) < 50'];
        yield 'constant first, at least' => [['50 >= count($lines)'], '$lines', 50, '50 >= count($lines)'];
        yield 'constant first, greater than' => [['50 > count($lines)'], '$lines', 49, '50 > count($lines)'];
        yield 'exactly' => [['count($lines) === 7'], '$lines', 7, 'count($lines) === 7'];
        yield 'exactly, constant first' => [['7 === count($lines)'], '$lines', 7, '7 === count($lines)'];
        yield 'a class constant' => [['count($lines) <= self::LIMIT'], '$lines', 12, 'count($lines) <= self::LIMIT'];
        yield 'a conjunct among others' => [['$total >= 0 && count($lines) <= 5 && $rate > 1'], '$lines', 5, '$total >= 0 && count($lines) <= 5 && $rate > 1'];
        yield 'a conjunct with and' => [['$total >= 0 and count($lines) <= 5'], '$lines', 5, '$total >= 0 and count($lines) <= 5'];
        yield 'a property' => [['count($order->lines) <= 8'], '$order->lines', 8, 'count($order->lines) <= 8'];
        yield 'the smallest bound of the list' => [['count($lines) <= 50', 'count($lines) <= 20 && $x', 'count($lines) <= 30'], '$lines', 20, 'count($lines) <= 20 && $x'];
        yield 'the largest bound for another list' => [['count($weights) <= 5', 'count($others) <= 8'], '$parts', 8, 'count($others) <= 8'];
        yield 'a bound below zero stops at zero' => [['count($lines) < 0'], '$lines', 0, 'count($lines) < 0'];
        yield 'a literal list' => [[], '[1, 2, 3]', 3, null];
        yield 'a literal list with a bound of another list' => [['count($lines) <= 9'], '[1, 2]', 9, 'count($lines) <= 9'];
        yield 'nothing' => [[], '$lines', 10, null];
        yield 'a disjunction gives no bound' => [['count($lines) <= 5 || $free'], '$lines', 10, null];
        yield 'a lower bound gives none' => [['count($lines) >= 5'], '$lines', 10, null];
        yield 'a negated bound gives none' => [['!(count($lines) <= 5)'], '$lines', 10, null];
        yield 'a constant that is not an int' => [['count($lines) <= self::NAME'], '$lines', 10, null];
        yield 'a count with two arguments' => [['count($lines, COUNT_RECURSIVE) <= 5'], '$lines', 10, null];
        yield 'a literal list with a spread' => [[], '[...$a, 1]', 10, null];
    }

    /**
     * @param list<string> $requires
     */
    #[DataProvider('boundCases')]
    public function testTheBoundIsReadFromTheRequires(array $requires, string $list, int $expected, ?string $origin): void
    {
        $bounds = $this->bounds($requires);

        self::assertSame([$expected, $origin], $bounds->boundOf(self::expression($list)));
    }

    public function testTheSizeOfALoopNestIsTheProductOfItsBounds(): void
    {
        self::assertSame(1, LoopBounds::product([]));
        self::assertSame(20, LoopBounds::product([20]));
        self::assertSame(400, LoopBounds::product([20, 20]));
        self::assertSame(0, LoopBounds::product([20, 0]));
        self::assertSame(PHP_INT_MAX, LoopBounds::product([PHP_INT_MAX, 3]));
    }

    public function testALoopNestOverTheBudgetIsRefusedWithItsSize(): void
    {
        $bounds = $this->bounds([]);

        self::assertNull($bounds->budgetRefusal([], 100));
        self::assertNull($bounds->budgetRefusal([10], 10));
        self::assertSame(
            'This foreach would be unrolled 500 times, more than contracts.maxLoopUnroll (100). Bound the list more tightly with #[Requires], or raise contracts.maxLoopUnroll.',
            $bounds->budgetRefusal([], 500),
        );
        self::assertSame(
            'These nested loops would be unrolled 20 × 20 = 400 times, more than contracts.maxLoopUnroll (100). Bound the lists more tightly with #[Requires], extract the inner loop into a smaller method of this file, or raise contracts.maxLoopUnroll.',
            $bounds->budgetRefusal([20], 20),
        );
        self::assertStringContainsString('unrolled 5 × 5 × 5 × 5 = 625 times', (string) $bounds->budgetRefusal([5, 5, 5], 5));
    }

    #[DataProvider('validLimits')]
    public function testTheLimitsAreAccepted(int $default, int $maximum): void
    {
        LoopBounds::validateLimits($default, $maximum);

        $this->addToAssertionCount(1);
    }

    /**
     * @return iterable<string, array{int, int}>
     */
    public static function validLimits(): iterable
    {
        yield 'the defaults' => [10, 100];
        yield 'the smallest' => [1, 1];
        yield 'equal' => [50, 50];
        yield 'the largest' => [10000, 10000];
    }

    #[DataProvider('invalidLimits')]
    public function testTheLimitsOutOfRangeAreRefused(int $default, int $maximum, string $message): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage($message);

        LoopBounds::validateLimits($default, $maximum);
    }

    /**
     * @return iterable<string, array{int, int, string}>
     */
    public static function invalidLimits(): iterable
    {
        yield 'no maximum' => [1, 0, 'contracts.maxLoopUnroll must be between 1 and 10000, 0 given.'];
        yield 'a maximum that is too large' => [10, 10001, 'contracts.maxLoopUnroll must be between 1 and 10000, 10001 given.'];
        yield 'a negative maximum' => [10, -5, 'contracts.maxLoopUnroll must be between 1 and 10000, -5 given.'];
        yield 'no default' => [0, 100, 'contracts.loopUnroll must be between 1 and contracts.maxLoopUnroll (100), 0 given.'];
        yield 'a default above the maximum' => [101, 100, 'contracts.loopUnroll must be between 1 and contracts.maxLoopUnroll (100), 101 given.'];
    }

    /**
     * @param list<string> $requires
     */
    private function bounds(array $requires): LoopBounds
    {
        $clauses = array_map(static fn (string $source): ContractClause => new ContractClause($source, self::expression($source), 1), $requires);
        $fold = static fn (Expr $expression): ?int => $expression instanceof Expr\ClassConstFetch && $expression->name instanceof \PhpParser\Node\Identifier && $expression->name->toString() === 'LIMIT' ? 12 : null;

        return new LoopBounds($clauses, $fold, 10, 100);
    }

    private static function expression(string $source): Expr
    {
        $statements = (new ParserFactory())->createForNewestSupportedVersion()->parse('<?php ' . $source . ';') ?? [];
        $statement = $statements[0] ?? null;
        self::assertInstanceOf(Stmt\Expression::class, $statement);

        return $statement->expr;
    }
}
