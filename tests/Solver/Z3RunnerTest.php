<?php

declare(strict_types=1);

namespace PhpstanContracts\Tests\Solver;

use PhpstanContracts\Solver\SolverFailure;
use PhpstanContracts\Solver\Verdict;
use PhpstanContracts\Solver\Z3Runner;
use PhpstanContracts\Tests\Support\RequiresZ3;
use PHPUnit\Framework\TestCase;

final class Z3RunnerTest extends TestCase
{
    use RequiresZ3;

    private Z3Runner $runner;

    protected function setUp(): void
    {
        $this->runner = new Z3Runner($this->requireZ3(), 10000);
    }

    public function testChecksComeBackInScriptOrderWithTheirVerdicts(): void
    {
        $results = $this->runner->run(<<<'SMT'
            (set-option :produce-models true)
            (declare-const x Int)
            (assert (> x 5))
            (echo "@@0")
            (check-sat)
            (get-value (x))
            (echo "@@1")
            (push 1)
            (assert (< x 3))
            (check-sat)
            (get-value (x))
            (pop 1)
            SMT, 2);

        self::assertCount(2, $results);
        self::assertSame(Verdict::Sat, $results[0]->verdict);
        self::assertSame(Verdict::Unsat, $results[1]->verdict);
        self::assertSame([], $results[1]->values, 'there is no model for an unsatisfiable check');
    }

    public function testASatisfiableCheckReturnsTheRequestedValues(): void
    {
        $results = $this->runner->run(<<<'SMT'
            (set-option :produce-models true)
            (declare-const x Int)
            (declare-const y Int)
            (declare-const flag Bool)
            (assert (and (= x 7) (= y (- 3)) flag))
            (echo "@@0")
            (check-sat)
            (get-value (x y flag))
            SMT, 1);

        self::assertSame(Verdict::Sat, $results[0]->verdict);
        self::assertSame('7', $results[0]->values['x']);
        self::assertSame(['-', '3'], $results[0]->values['y']);
        self::assertSame('true', $results[0]->values['flag']);
    }

    public function testRichValuesAreReturnedAsTheyAreWritten(): void
    {
        $results = $this->runner->run(<<<'SMT'
            (set-option :produce-models true)
            (declare-datatypes ((Opt 0)) (((none) (some (val Int)))))
            (declare-const s String)
            (declare-const f Float64)
            (declare-const o Opt)
            (assert (= s "a""b"))
            (assert (fp.isNaN f))
            (assert (= o (some 4)))
            (echo "@@0")
            (check-sat)
            (get-value (s f o))
            SMT, 1);

        self::assertSame('"a""b"', $results[0]->values['s']);
        self::assertSame(['_', 'NaN', '11', '53'], $results[0]->values['f']);
        self::assertSame(['some', '4'], $results[0]->values['o']);
    }

    public function testWindowsLineEndingsInTheScriptAreHandled(): void
    {
        $results = $this->runner->run(str_replace("\n", "\r\n", "(declare-const x Int)\n(assert (> x 0))\n(echo \"@@0\")\n(check-sat)\n(get-value (x))\n"), 1);

        self::assertSame(Verdict::Sat, $results[0]->verdict);
    }

    public function testAMalformedScriptIsAFailureAndNeverAnAnswer(): void
    {
        $this->expectException(SolverFailure::class);
        $this->expectExceptionMessage('no answer for check 0');

        $this->runner->run('(declare-const', 1);
    }

    public function testAScriptThatAnswersTooFewChecksIsAFailure(): void
    {
        $this->expectException(SolverFailure::class);
        $this->expectExceptionMessage('no answer for check 1');

        $this->runner->run("(declare-const x Int)\n(echo \"@@0\")\n(check-sat)\n(get-value (x))\n", 2);
    }

    public function testATimeoutIsReportedAsUnknownAndNeverAsAProof(): void
    {
        $runner = new Z3Runner($this->requireZ3(), 300);

        // Fermat's equation for exponent three has no solution, which Z3 cannot establish quickly.
        $results = $runner->run(<<<'SMT'
            (set-option :produce-models true)
            (declare-const x Int)
            (declare-const y Int)
            (declare-const z Int)
            (assert (and (> x 0) (> y 0) (> z 0)))
            (assert (= (+ (* x x x) (* y y y)) (* z z z)))
            (echo "@@0")
            (check-sat)
            (get-value (x y z))
            SMT, 1);

        self::assertSame(Verdict::Unknown, $results[0]->verdict);
    }
}
