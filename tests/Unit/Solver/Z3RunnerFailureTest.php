<?php

declare(strict_types=1);

namespace PhpstanContracts\Tests\Unit\Solver;

use InvalidArgumentException;
use PhpstanContracts\Solver\SolverFailure;
use PhpstanContracts\Solver\Z3Runner;
use PHPUnit\Framework\TestCase;

final class Z3RunnerFailureTest extends TestCase
{
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
}
