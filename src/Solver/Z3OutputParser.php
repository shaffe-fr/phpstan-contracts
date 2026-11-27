<?php

declare(strict_types=1);

namespace PhpstanContracts\Solver;

use PhpstanContracts\Smt\SExpr;

/**
 * Reading a Z3 answer: the `@@N` blocks of the output, one per check of the script.
 *
 * The disposable and the persistent runner share this reader: what a block means does not
 * depend on how the script reached Z3, so both read it the same way and the answers they
 * return are comparable.
 */
final class Z3OutputParser
{
    /**
     * @param list<int> $skipped the checks that are not in the script, `unknown` as remembered
     * @param bool $partial whether the process was killed by the budget, so that the output stops anywhere
     * @param bool $capped whether the limit of the checks was cut short by the budget, so that their `unknown` is the budget's
     * @return list<SolverResult>
     */
    public function parse(string $output, int $checkCount, array $skipped = [], bool $partial = false, bool $capped = false): array
    {
        if ($partial) {
            // The last line may be cut in two.
            $end = strrpos($output, "\n");
            $output = $end === false ? '' : substr($output, 0, $end + 1);
        }

        // A command that fails is skipped and the script goes on, so a dropped assertion
        // would leave no trace but a weaker problem. The only expected error is the
        // `get-value` that follows an `unsat` or `unknown` answer.
        preg_match_all('/^\(error\b.*$/m', $output, $errors);
        foreach ($errors[0] as $error) {
            if (preg_match('/^\(error "line \d+ column \d+: model is not available"\)\s*$/', $error) !== 1) {
                throw new SolverFailure('Z3 reported an error: ' . substr(trim($error), 0, 400));
            }
        }

        preg_match_all('/^@@(\d+)\R(.*?)(?=^@@\d+\R|\z)/ms', $output, $blocks, PREG_SET_ORDER);
        $last = array_key_last($blocks);
        /** @var array<int, SolverResult> $results */
        $results = [];
        foreach ($blocks as $position => $block) {
            if ($partial && $position === $last) {
                // Whatever follows the last marker may be unfinished: only an answer that carries no model is certain.
                try {
                    $result = $this->parseBlock($block[2], $output);
                } catch (SolverFailure) {
                    continue;
                }
                if ($result->verdict === Verdict::Sat) {
                    continue;
                }
                $results[(int) $block[1]] = $result;
                continue;
            }
            $results[(int) $block[1]] = $this->parseBlock($block[2], $output);
        }

        $ordered = [];
        for ($index = 0; $index < $checkCount; ++$index) {
            if (in_array($index, $skipped, true)) {
                $ordered[] = new SolverResult(Verdict::Unknown, [], UnknownReason::Remembered);
            } elseif (isset($results[$index])) {
                $result = $results[$index];
                $ordered[] = $capped && $result->verdict === Verdict::Unknown ? new SolverResult(Verdict::Unknown, [], UnknownReason::Budget) : $result;
            } elseif ($partial) {
                $ordered[] = new SolverResult(Verdict::Unknown, [], UnknownReason::Budget);
            } else {
                throw new SolverFailure('Z3 produced no answer for check ' . $index . ': ' . $this->excerpt($output));
            }
        }

        return $ordered;
    }

    public function parseBlock(string $block, string $fullOutput): SolverResult
    {
        if (preg_match('/^\s*(sat|unsat|unknown)\b/', $block, $match) !== 1) {
            throw new SolverFailure('Unexpected Z3 answer: ' . $this->excerpt($fullOutput));
        }

        $verdict = Verdict::from($match[1]);
        if ($verdict !== Verdict::Sat) {
            return new SolverResult($verdict);
        }

        $expressions = SExpr::parse(substr($block, strlen($match[0])));
        $values = [];
        $pairs = $expressions[0] ?? [];
        if (is_array($pairs)) {
            foreach ($pairs as $pair) {
                if (is_array($pair) && count($pair) === 2 && (is_string($pair[0]) || is_array($pair[0]))) {
                    /** @var string|list<mixed> $value */
                    $value = $pair[1];
                    // A probe term such as (select (List_Int_items l@1) 0) is keyed by its text.
                    $values[SExpr::render($pair[0])] = $value;
                }
            }
        }

        return new SolverResult($verdict, $values);
    }

    private function excerpt(string $output): string
    {
        return substr(trim($output), 0, 400);
    }
}
