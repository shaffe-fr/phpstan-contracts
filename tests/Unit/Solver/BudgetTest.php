<?php

declare(strict_types=1);

namespace PhpstanContracts\Tests\Unit\Solver;

use InvalidArgumentException;
use PhpstanContracts\Solver\MethodBudget;
use PhpstanContracts\Solver\RunBudget;
use PhpstanContracts\Solver\TimeLimits;
use PhpstanContracts\Tests\Support\FakeClock;
use PHPUnit\Framework\TestCase;

final class BudgetTest extends TestCase
{
    public function testAMethodBudgetIsSpentWhenTheClockReachesItsDeadline(): void
    {
        $clock = new FakeClock(5_000);
        $budget = new MethodBudget($clock, 1_000);

        self::assertSame(6_000, $budget->deadlineMs());
        self::assertFalse($budget->isExhausted());
        $clock->advance(999);
        self::assertFalse($budget->isExhausted());
        $clock->advance(1);
        self::assertTrue($budget->isExhausted());
    }

    public function testAMethodBudgetOfZeroIsNeverSpent(): void
    {
        $clock = new FakeClock();
        $budget = new MethodBudget($clock, 0);
        $clock->advance(999_999_999);

        self::assertNull($budget->deadlineMs());
        self::assertFalse($budget->isExhausted());
    }

    public function testARunBudgetAddsUpWhatIsSpent(): void
    {
        $budget = new RunBudget(100);
        $budget->spend(60);
        self::assertFalse($budget->isExhausted());
        $budget->spend(40);

        self::assertSame(100, $budget->spentMs());
        self::assertTrue($budget->isExhausted());
    }

    public function testARunBudgetOfZeroIsNeverSpent(): void
    {
        $budget = new RunBudget();
        $budget->spend(999_999_999);

        self::assertFalse($budget->isExhausted());
    }

    public function testANegativeRunBudgetIsRefused(): void
    {
        $this->expectException(InvalidArgumentException::class);
        new RunBudget(-1);
    }

    public function testTheProcessIsKilledAtTheBudgetOnlyWhenTheBudgetComesFirst(): void
    {
        // Two checks of 10 s need 20 s, and the margin makes the limit 30 s.
        self::assertSame(30_000, TimeLimits::killMs(10_000, 2, 3_600_000));
        self::assertSame(30_000, TimeLimits::killMs(10_000, 2, 30_000));
        self::assertSame(29_999, TimeLimits::killMs(10_000, 2, 29_999));
        self::assertSame(1, TimeLimits::killMs(10_000, 2, 1));
    }
}
