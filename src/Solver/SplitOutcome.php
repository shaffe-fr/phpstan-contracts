<?php

declare(strict_types=1);

namespace PhpstanContracts\Solver;

use LogicException;

/**
 * What the answers of the cases of a split say about the check they split.
 *
 * Only `sat` and `unsat` are read. A case with no answer, because Z3 answered `unknown`, ran out of
 * time, was stopped or failed, is undecided, and a check with an undecided case stays `unknown`
 * unless another case has a model.
 */
final readonly class SplitOutcome
{
    /**
     * @param list<int|string> $undecided the lengths that no case decided, then the description of the case outside the bound if it was not decided
     */
    public function __construct(
        public SolverResult $result,
        public array $undecided = [],
    ) {
    }

    /**
     * @param list<SplitCase> $cases a partition of the lengths
     * @param array<int, SolverResult|null> $answers by position in `$cases`, null when the case has no answer
     * @param bool $cut whether the budget stopped a case that has no answer, which makes the check `unknown` for that reason
     */
    public static function combine(array $cases, array $answers, bool $cut = false): self
    {
        if ($cases === []) {
            throw new LogicException('A split has at least one case.');
        }

        $undecided = [];
        foreach ($cases as $position => $case) {
            $answer = $answers[$position] ?? null;
            if ($answer?->verdict === Verdict::Sat) {
                return new self($answer);
            }
            if ($answer?->verdict !== Verdict::Unsat) {
                $undecided[] = $case->length ?? $case->description;
            }
        }

        if ($undecided === []) {
            return new self(new SolverResult(Verdict::Unsat));
        }

        return new self(new SolverResult(Verdict::Unknown, [], $cut ? UnknownReason::Budget : null), $undecided);
    }
}
