<?php

declare(strict_types=1);

namespace PhpstanContracts\Tests\Unit\Solver;

use InvalidArgumentException;
use PhpstanContracts\Solver\SolverFailure;
use PhpstanContracts\Solver\Z3Runner;
use PhpstanContracts\Tests\Support\RequiresZ3;
use PHPUnit\Framework\TestCase;

final class Z3RunnerFailureTest extends TestCase
{
    use RequiresZ3;

    public function testAMissingExecutableIsReportedWithAHowToFix(): void
    {
        $runner = new Z3Runner('no-such-solver-on-this-machine');

        try {
            $runner->run('(check-sat)', 1);
            self::fail('A missing solver must not be silently skipped.');
        } catch (SolverFailure $failure) {
            self::assertStringContainsString('"no-such-solver-on-this-machine" not found', $failure->getMessage());
            self::assertStringContainsString('phpstan-contracts-install-z3', $failure->getMessage());
            self::assertStringContainsString('z3Binary', $failure->getMessage());
        }
    }

    public function testATimeoutOfZeroOrLessIsRefusedAtConstruction(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('between 1 and 86400000 milliseconds, 0 given');

        new Z3Runner('z3', 0);
    }

    public function testATimeoutOfMoreThanADayIsRefusedAtConstruction(): void
    {
        $this->expectException(InvalidArgumentException::class);

        new Z3Runner('z3', 86_400_001);
    }

    public function testAScriptWithoutChecksIsRefused(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('between 1 and 100000 checks, 0 given');

        (new Z3Runner('z3'))->run('', 0);
    }

    public function testAScriptLineThatZ3RejectsIsAFailureNotAnAnswer(): void
    {
        $runner = new Z3Runner($this->requireZ3());

        try {
            $runner->run("(echo \"@@0\")\n(assert (> undeclared 0))\n(check-sat)\n", 1);
            self::fail('A line that Z3 drops from the problem must not turn into an answer.');
        } catch (SolverFailure $failure) {
            self::assertStringContainsString('Z3 rejected a line of the script', $failure->getMessage());
            self::assertStringContainsString('undeclared', $failure->getMessage());
        }
    }

    public function testTheMissingModelAfterAnUnsatAnswerIsNotAFailure(): void
    {
        $runner = new Z3Runner($this->requireZ3());

        $results = $runner->run("(set-option :produce-models true)\n(declare-const x Int)\n(echo \"@@0\")\n(assert (> x 0))\n(assert (< x 0))\n(check-sat)\n(get-value (x))\n", 1);

        self::assertSame('unsat', $results[0]->verdict->value);
    }
}
