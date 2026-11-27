<?php

declare(strict_types=1);

namespace PhpstanContracts\Tests\Solver;

use PhpstanContracts\Solver\PersistentZ3Runner;
use PhpstanContracts\Solver\ProofCache;
use PhpstanContracts\Solver\SolverResult;
use PhpstanContracts\Solver\Z3Runner;
use PhpstanContracts\Tests\Support\RequiresZ3;
use PHPUnit\Framework\TestCase;

/**
 * The disposable and the persistent runners must answer a script the same way: only `sat` and
 * `unsat` are proofs, an `unknown` is never read as either, and the values of a model come back
 * keyed the same way.
 *
 * The scripts below are chosen so that a leak of one script's state into the next would flip an
 * answer: `x` is declared to `6` and later re-declared to `10` on the same persistent process, a
 * datatype and a `push`/`pop` frame must both be wiped by the `(reset)` in between, and a global
 * option, which `(reset)` keeps, must not reach the next script. No answer depends on a race at
 * the time limit: the `unknown` comes from a resource limit of one unit, not from a clock.
 */
final class PersistentVsDisposableTest extends TestCase
{
    use RequiresZ3;

    /**
     * @return array<int, array{string, int}>
     */
    private static function scripts(): array
    {
        return [
            // The model of a satisfiable script.
            [
                <<<'SMT'
                (set-option :produce-models true)
                (declare-const x Int)
                (assert (= x 6))
                (echo "@@0")
                (check-sat)
                (get-value (x))
                SMT,
                1,
            ],
            // Canary: `x` is re-declared to a different value on the same persistent process.
            // Without a clean `(reset)`, the previous `x = 6` would make this unsatisfiable.
            [
                <<<'SMT'
                (set-option :produce-models true)
                (declare-const x Int)
                (assert (= x 10))
                (echo "@@0")
                (check-sat)
                (get-value (x))
                SMT,
                1,
            ],
            // A datatype must be wiped between scripts too.
            [
                <<<'SMT'
                (set-option :produce-models true)
                (declare-datatypes ((Opt 0)) (((none) (some (val Int)))))
                (declare-const o Opt)
                (assert (= o (some 4)))
                (echo "@@0")
                (check-sat)
                (get-value (o))
                SMT,
                1,
            ],
            // A contradiction, no model to read.
            [
                <<<'SMT'
                (declare-const a Int)
                (assert (> a 100))
                (assert (< a 50))
                (echo "@@0")
                (check-sat)
                SMT,
                1,
            ],
            // Two checks: the second is inside a `push` that is `pop`ed before the script ends.
            [
                <<<'SMT'
                (set-option :produce-models true)
                (declare-const b Int)
                (assert (= b 3))
                (echo "@@0")
                (check-sat)
                (get-value (b))
                (push 1)
                (assert (= b 4))
                (echo "@@1")
                (check-sat)
                (pop 1)
                SMT,
                2,
            ],
            // An `unsat` followed by its `get-value`, whose expected error must read the same.
            [
                <<<'SMT'
                (set-option :produce-models true)
                (declare-const c Int)
                (assert (and (> c 5) (< c 3)))
                (echo "@@0")
                (check-sat)
                (get-value (c))
                SMT,
                1,
            ],
            // A global option survives a `(reset)` in Z3: this one makes every check `unknown`...
            [
                <<<'SMT'
                (set-option :produce-models true)
                (set-option :rlimit 1)
                (declare-const d Int)
                (assert (> (* d d) 2))
                (echo "@@0")
                (check-sat)
                SMT,
                1,
            ],
            // ...and must not reach the next script, which is a plain model.
            [
                <<<'SMT'
                (set-option :produce-models true)
                (declare-const e Int)
                (assert (> (* e e) 2))
                (assert (< e 0))
                (assert (> e (- 5)))
                (echo "@@0")
                (check-sat)
                (get-value (e))
                SMT,
                1,
            ],
            // A script far larger than a pipe buffer.
            [self::largeScript(), 1],
        ];
    }

    private static function largeScript(): string
    {
        $lines = ['(set-option :produce-models true)'];
        for ($index = 0; $index < 4000; ++$index) {
            $lines[] = sprintf('(declare-const v%d Int)', $index);
            $lines[] = sprintf('(assert (= v%d %d))', $index, $index);
        }
        $lines[] = '(echo "@@0")';
        $lines[] = '(check-sat)';
        $lines[] = '(get-value (v3999))';

        return implode("\n", $lines) . "\n";
    }

    public function testTheTwoRunnersAnswerEveryScriptIdentically(): void
    {
        $binary = $this->requireZ3();
        $disposable = new Z3Runner($binary, 30000, new ProofCache(null));
        // One persistent process answers every script in order, as a worker would.
        $persistent = new PersistentZ3Runner($binary, 30000, new ProofCache(null));

        $scripts = self::scripts();
        foreach ($scripts as $index => [$script, $checkCount]) {
            $disposableResults = $disposable->run($script, $checkCount);
            $persistentResults = $persistent->run($script, $checkCount);
            self::assertScriptResultsIdentical('script ' . $index, $disposableResults, $persistentResults);
        }
    }

    /**
     * @param list<SolverResult> $expected
     * @param list<SolverResult> $actual
     */
    private static function assertScriptResultsIdentical(string $label, array $expected, array $actual): void
    {
        self::assertCount(count($expected), $actual, 'the runners disagree on the number of checks of ' . $label);

        foreach ($expected as $index => $a) {
            $b = $actual[$index];
            self::assertSame($a->verdict, $b->verdict, $label . ', check ' . $index . ': verdicts differ');
            self::assertSame($a->values, $b->values, $label . ', check ' . $index . ': model values differ');
            self::assertSame($a->reason?->name, $b->reason?->name, $label . ', check ' . $index . ': unknown reasons differ');
        }
    }
}
