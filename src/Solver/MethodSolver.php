<?php

declare(strict_types=1);

namespace PhpstanContracts\Solver;

use InvalidArgumentException;
use PhpstanContracts\Translate\Encoding;

/**
 * Asks Z3 every check of one method, within the wall-clock budget of the method.
 *
 * The strategy is the main run, then, for a script with a loop, one retry of each check that is
 * still `unknown` and the split of that check by the length of the iterated list, see {@see CaseSplit}.
 * Only `sat` and `unsat` are ever read as an answer. A check that was not tried, because the budget
 * ran out, is `unknown` with the reason {@see UnknownReason::Budget}, or {@see UnknownReason::RunBudget} when
 * the run budget was spent, and
 * so is a check that an earlier run left undecided, with the reason {@see UnknownReason::Remembered}.
 *
 * A check that stayed `unknown` after the whole strategy is remembered, see {@see ProofCache}. One
 * that was cut by a budget is not: it was not tried in full, and the next run, which skips what is
 * remembered and starts with a fresh budget, goes further down the list of the checks left.
 */
final class MethodSolver
{
    private const MAXIMUM_BUDGET_MS = 86_400_000;

    public function __construct(
        private readonly CheckRunner $runner,
        private readonly ScriptRenderer $renderer,
        private readonly Clock $clock = new SystemClock(),
        private readonly int $methodBudgetMs = 60_000,
        private readonly bool $rememberUndecided = true,
    ) {
        if ($methodBudgetMs < 0 || $methodBudgetMs > self::MAXIMUM_BUDGET_MS) {
            throw new InvalidArgumentException(sprintf('The method budget must be between 0 (no limit) and %d milliseconds, %d given.', self::MAXIMUM_BUDGET_MS, $methodBudgetMs));
        }
    }

    /**
     * @throws SolverFailure a genuine failure of the solver
     * @throws RunBudgetExhausted when the run budget was spent before the first question
     */
    public function solve(Encoding $encoding): MethodAnswer
    {
        $budget = new MethodBudget($this->clock, $this->methodBudgetMs);
        $checkCount = $this->renderer->checkCount($encoding);

        /** @var array<int, string> $scripts the script of each check alone, the key of what is remembered about it */
        $scripts = [];
        $skipped = [];
        if ($this->rememberUndecided && $this->runner->cacheEnabled()) {
            for ($check = 0; $check < $checkCount; ++$check) {
                $scripts[$check] = $this->renderer->renderSingleCheck($encoding, $check);
                if ($this->runner->isRemembered($scripts[$check], $this->methodBudgetMs)) {
                    $skipped[] = $check;
                }
            }
        }

        $started = $this->clock->nowMs();
        $results = count($skipped) === $checkCount
            ? array_fill(0, $checkCount, new SolverResult(Verdict::Unknown, [], UnknownReason::Remembered))
            : $this->runner->run($this->renderer->render($encoding, $skipped), $checkCount, $budget->deadlineMs(), $skipped);
        $timings = ['main' => $this->clock->nowMs() - $started, 'retry' => 0, 'split' => 0];

        $undecidedLengths = [];
        /** @var array<int, true> $settled the checks that were tried in full and are still `unknown` */
        $settled = [];
        if ($encoding->needsSolverFallback) {
            [$results, $undecidedLengths, $settled] = $this->retryUndecided($encoding, $results, $budget, $timings);
        } else {
            foreach ($results as $check => $result) {
                if ($result->verdict === Verdict::Unknown && $result->reason === null) {
                    $settled[$check] = true;
                }
            }
        }

        if ($this->rememberUndecided && $this->runner->cacheEnabled()) {
            foreach ($settled as $check => $unused) {
                if ($results[$check]->verdict === Verdict::Unknown && $results[$check]->reason === null) {
                    $this->runner->remember($scripts[$check] ?? $this->renderer->renderSingleCheck($encoding, $check), $this->methodBudgetMs);
                }
            }
        }

        return new MethodAnswer($results, $undecidedLengths, $timings['main'], $timings['retry'], $timings['split'], $budget->isExhausted());
    }

    /**
     * Asks again, alone and with Z3's default options, every check of a loop script that came
     * back `unknown`: the option of the main script suits most checks, and a few are decided
     * only by the default solver. The new answer replaces `unknown`; if the retry fails or is
     * `unknown` too, the first answer stays, and `unknown` is never read as a proof.
     *
     * A check that the retry leaves `unknown` is asked once more, once per length of the list that
     * drives the unrolling, when the loop is bounded by few enough lengths. That is the same question
     * cut into a partition, so its answer replaces `unknown` too, and the lengths that it could not
     * decide are kept for the message.
     *
     * The budget of the method is looked at before the retry and the split of each check, and
     * each of them gets no more than what is left.
     *
     * @param list<SolverResult> $results
     * @param array{main: int, retry: int, split: int} $timings the measured milliseconds, to which the time of the retries and of the splits is added
     * @return array{list<SolverResult>, array<int, list<int|string>>, array<int, true>} the results, by check number the cases of the split that were not decided, and the checks left `unknown` by the whole strategy
     */
    private function retryUndecided(Encoding $encoding, array $results, MethodBudget $budget, array &$timings): array
    {
        $undecided = [];
        $settled = [];
        $split = $encoding->splitLength();
        $cases = $split === null ? null : CaseSplit::cases($split[0], $split[1]);
        foreach ($results as $check => $result) {
            if ($result->verdict !== Verdict::Unknown || $result->reason !== null) {
                continue;
            }
            $notTried = new SolverResult(Verdict::Unknown, [], UnknownReason::Budget);
            if ($budget->isExhausted()) {
                $results[$check] = $notTried;
                continue;
            }
            try {
                $single = $this->renderer->renderSingleCheck($encoding, $check);
                $started = $this->clock->nowMs();
                try {
                    $results[$check] = $this->runner->run($single, 1, $budget->deadlineMs())[0];
                } finally {
                    $timings['retry'] += $this->clock->nowMs() - $started;
                }
                if ($results[$check]->verdict !== Verdict::Unknown || $results[$check]->reason !== null) {
                    continue;
                }
                // The satisfiability of the assumptions is not about the body, so the length of an
                // iterated list is not part of its script.
                if ($cases === null || $check === 0) {
                    $settled[$check] = true;
                    continue;
                }
                if ($budget->isExhausted()) {
                    $results[$check] = $notTried;
                    continue;
                }
                $started = $this->clock->nowMs();
                try {
                    $outcome = $this->runner->runSplit(
                        $single,
                        $cases,
                        array_map(fn ($case): string => $this->renderer->renderSingleCheck($encoding, $check, $case), $cases),
                        $budget->deadlineMs(),
                    );
                } finally {
                    $timings['split'] += $this->clock->nowMs() - $started;
                }
            } catch (RunBudgetExhausted) {
                $results[$check] = new SolverResult(Verdict::Unknown, [], UnknownReason::RunBudget);
                continue;
            } catch (SolverFailure) {
                continue;
            }
            $results[$check] = $outcome->result;
            if ($outcome->undecided !== []) {
                $undecided[$check] = $outcome->undecided;
            }
            if ($outcome->result->verdict === Verdict::Unknown && $outcome->result->reason === null) {
                $settled[$check] = true;
            }
        }

        return [$results, $undecided, $settled];
    }
}
