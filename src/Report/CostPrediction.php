<?php

declare(strict_types=1);

namespace PhpstanContracts\Report;

use PhpstanContracts\Solver\CaseSplit;
use PhpstanContracts\Solver\Z3Runner;
use PhpstanContracts\Translate\Encoding;

/**
 * What the encoder knows about the cost of a method before Z3 is asked: its structure, and the
 * longest the strategy of {@see \PhpstanContracts\Solver\MethodSolver} can take. It reads the encoding
 * and the configured limits only, never a clock and never the machine, so the same code gives the
 * same prediction on every run and in every diagnostic that PHPStan caches.
 *
 * It predicts an order of magnitude and a risk of `unknown`, not seconds. It is never a verdict, and
 * a method is never refused on it.
 */
final readonly class CostPrediction
{
    /** The processes of a split that run at once when `contracts.parallelism` is not set: the cap of the default. */
    public const DEFAULT_PARALLELISM = 4;

    /** Kill margin that the main run adds to the limits of its checks, see {@see \PhpstanContracts\Solver\TimeLimits::processSeconds()}. */
    private const KILL_MARGIN_MS = 10_000;

    /**
     * The structure from which a method is flagged by `contracts.costly`: divisions and modulos by a
     * variable inside the copies of the loop bodies, summed over the unrolled iterations. A proportional
     * split of 3 lines has 3, of 5 lines 5 and of 8 lines 8; the examples that are proven fast have none.
     * Products of two variables are not counted: 20 of them in a loop are decided in a moment.
     */
    public const COSTLY_DIVISIONS_IN_LOOPS = 5;

    /**
     * @param list<array{line: int, bound: int, depth: int}> $loops the unrolled loops, by line, with the bound of the unrolling and the nesting depth (0 is outermost)
     * @param int $divisionsInLoops the divisions and modulos by a variable inside the copies of the loop bodies
     * @param int $cases the cases of the split of a check by the length of the iterated list, 0 when there is none
     * @param int $worstCaseMs the longest the whole strategy can take for this method, capped by the budget of the method when there is one
     */
    public function __construct(
        public int $checks,
        public array $loops,
        public int $unrolledIterations,
        public int $nonLinear,
        public int $divisionsInLoops,
        public int $cases,
        public int $worstCaseMs,
        public bool $capped,
    ) {
    }

    public static function of(Encoding $encoding, int $checks, int $timeoutMs, int $methodBudgetMs, ?int $parallelism = null): self
    {
        $loops = [];
        $depths = $encoding->loopDepths();
        $unrolled = $encoding->unrolledLoops();
        ksort($unrolled);
        foreach ($unrolled as $line => $loop) {
            $loops[] = ['line' => $line, 'bound' => $loop->iterations, 'depth' => $depths[$line] ?? 0];
        }

        $split = $encoding->splitLength();
        $cases = $split === null ? null : CaseSplit::cases($split[0], $split[1]);
        $caseCount = $cases === null ? 0 : count($cases);

        // The main run, then for each check that stays unknown the retry and the cases of the split in
        // waves of `parallelism` processes, each case with its seeds. Each question gets `timeoutMs`.
        $processes = max(1, $parallelism ?? self::DEFAULT_PARALLELISM);
        $waves = intdiv($caseCount * Z3Runner::SEEDS + $processes - 1, $processes);
        $perUnknown = ($encoding->needsSolverFallback ? $timeoutMs : 0) + $waves * $timeoutMs;
        $worst = $checks * $timeoutMs + self::KILL_MARGIN_MS + ($encoding->needsSolverFallback ? $checks * $perUnknown : 0);
        $capped = $methodBudgetMs > 0 && $worst > $methodBudgetMs;

        return new self(
            $checks,
            $loops,
            $encoding->unrolledIterations(),
            $encoding->nonLinearCount(),
            $encoding->divisionsInLoops(),
            $caseCount,
            $capped ? $methodBudgetMs : $worst,
            $capped,
        );
    }

    /**
     * Whether the structure is one where Z3 is likely to leave checks undecided: an unrolled loop
     * whose body holds many divisions or modulos by a variable, see
     * {@see self::COSTLY_DIVISIONS_IN_LOOPS}. It is a risk, not a measure.
     */
    public function isCostly(): bool
    {
        return $this->loops !== [] && $this->divisionsInLoops >= self::COSTLY_DIVISIONS_IN_LOOPS;
    }

    /**
     * @return array{checks: int, loops: list<array{line: int, bound: int, depth: int}>, unrolledIterations: int, nonLinear: int, divisionsInLoops: int, cases: int, worstCaseMs: int, capped: bool}
     */
    public function toArray(): array
    {
        return [
            'checks' => $this->checks,
            'loops' => $this->loops,
            'unrolledIterations' => $this->unrolledIterations,
            'nonLinear' => $this->nonLinear,
            'divisionsInLoops' => $this->divisionsInLoops,
            'cases' => $this->cases,
            'worstCaseMs' => $this->worstCaseMs,
            'capped' => $this->capped,
        ];
    }
}
