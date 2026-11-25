<?php

declare(strict_types=1);

namespace PhpstanContracts\Tests\Unit\Solver;

use LogicException;
use PhpstanContracts\Solver\CaseSplit;
use PhpstanContracts\Solver\SolverResult;
use PhpstanContracts\Solver\SplitOutcome;
use PhpstanContracts\Solver\Verdict;
use PHPUnit\Framework\TestCase;

final class SplitOutcomeTest extends TestCase
{
    public function testAnUnsatisfiableCaseForEveryCaseIsAnUnsatisfiableCheck(): void
    {
        $outcome = SplitOutcome::combine($this->cases(), [$this->unsat(), $this->unsat(), $this->unsat(), $this->unsat()]);

        self::assertSame(Verdict::Unsat, $outcome->result->verdict);
        self::assertSame([], $outcome->undecided);
    }

    public function testAModelOfOneCaseIsAModelOfTheCheck(): void
    {
        $model = new SolverResult(Verdict::Sat, ['x@1' => '7']);

        $outcome = SplitOutcome::combine($this->cases(), [$this->unsat(), $model, null, new SolverResult(Verdict::Unknown)]);

        self::assertSame(Verdict::Sat, $outcome->result->verdict);
        self::assertSame(['x@1' => '7'], $outcome->result->values);
        self::assertSame([], $outcome->undecided);
    }

    public function testACaseThatZ3LeftUnknownKeepsTheCheckUnknownAndIsListed(): void
    {
        $outcome = SplitOutcome::combine($this->cases(), [$this->unsat(), new SolverResult(Verdict::Unknown), $this->unsat(), $this->unsat()]);

        self::assertSame(Verdict::Unknown, $outcome->result->verdict);
        self::assertSame([1], $outcome->undecided);
    }

    public function testACaseWithoutAnAnswerIsUndecidedNeverUnsatisfiable(): void
    {
        // A killed, timed out or failed process leaves no answer: the position is null or absent.
        $outcome = SplitOutcome::combine($this->cases(), [$this->unsat(), null, $this->unsat()]);

        self::assertSame(Verdict::Unknown, $outcome->result->verdict);
        self::assertSame([1, 'outside 0..2'], $outcome->undecided);
    }

    public function testNoAnswerAtAllIsUnknownWithEveryCaseListed(): void
    {
        $outcome = SplitOutcome::combine($this->cases(), []);

        self::assertSame(Verdict::Unknown, $outcome->result->verdict);
        self::assertSame([0, 1, 2, 'outside 0..2'], $outcome->undecided);
    }

    public function testASplitWithoutCasesProvesNothing(): void
    {
        $this->expectException(LogicException::class);
        SplitOutcome::combine([], []);
    }

    /**
     * @return list<\PhpstanContracts\Solver\SplitCase>
     */
    private function cases(): array
    {
        return CaseSplit::cases('LEN', 2) ?? [];
    }

    private function unsat(): SolverResult
    {
        return new SolverResult(Verdict::Unsat);
    }
}
