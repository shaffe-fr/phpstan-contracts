<?php

declare(strict_types=1);

namespace PhpstanContracts\Report;

use PhpstanContracts\Translate\Encoding;
use PhpstanContracts\Translate\LoopBound;

/**
 * Says what makes a check that Z3 left undecided expensive, as far as the encoder knows, and what
 * the author can do about it. Only the encoder's own record is used, so what it names is in the
 * script: the loops it unrolls and their bound, the products and the divisions or modulos that are
 * not linear, and the lengths of the iterated list that a split by length could not decide.
 */
final class UnknownExplainer
{
    /**
     * Empty for a check whose method has neither a loop nor a non-linear operation.
     *
     * @param list<int|string> $undecidedLengths the cases of the split by length that were not decided: lengths, and the description of the case outside the bound
     */
    public function explain(Encoding $encoding, array $undecidedLengths = []): string
    {
        $loops = $encoding->unrolledLoops();
        ksort($loops);
        $operations = $encoding->nonLinearOperations();
        if ($loops === [] && $operations === []) {
            return '';
        }

        $unrolled = [];
        foreach ($loops as $line => $loop) {
            $unrolled[] = sprintf('the foreach at line %d up to %d iterations', $line, $loop->iterations);
        }
        $nonLinear = [];
        foreach ($operations as $kind => $source) {
            $nonLinear[] = sprintf('%s (`%s`)', match ($kind) {
                'product' => 'a product of two variables',
                'division' => 'a division by a variable',
                'modulo' => 'a modulo by a variable',
            }, $source);
        }

        $parts = [];
        if ($loops !== []) {
            $parts[] = sprintf(
                'The proof unrolls %s%s.',
                $this->join($unrolled),
                $nonLinear === [] ? '' : sprintf(', and the code has %s', $this->join($nonLinear)),
            );
        } else {
            $parts[] = sprintf('The code has %s, and Z3 decides non-linear arithmetic only in part.', $this->join($nonLinear));
        }

        $split = $encoding->splitLength();
        $subject = $split[2] ?? ($loops === [] ? null : reset($loops)->subject);
        if ($undecidedLengths !== [] && $subject !== null) {
            $lengths = array_values(array_filter($undecidedLengths, is_int(...)));
            $outside = array_values(array_filter($undecidedLengths, is_string(...)));
            $named = [];
            if ($lengths !== []) {
                $named[] = sprintf('the length%s %s', count($lengths) > 1 ? 's' : '', $this->join(array_map(strval(...), $lengths)));
            }
            foreach ($outside as $description) {
                $named[] = 'the lengths ' . $description;
            }
            $parts[] = sprintf('The split of the proof by the length of %s did not decide %s.', $subject, $this->join($named));
        }

        $ways = [];
        $widest = array_reduce($loops, static fn (?LoopBound $best, LoopBound $loop): LoopBound => $best === null || $loop->iterations > $best->iterations ? $loop : $best);
        if ($widest !== null && $widest->iterations > 1) {
            $ways[] = $widest->nameable
                ? sprintf('a smaller bound with #[Requires(\'count(%s) <= %d\')]', $widest->subject, min(3, $widest->iterations - 1))
                : 'a smaller bound on the list it is built from with #[Requires]';
        }
        $ways[] = 'a longer contracts.timeoutMs';
        $ways[] = 'a #[Requires] that narrows the ranges';
        $parts[] = sprintf('Try %s.', $this->join($ways, 'or'));

        return implode(' ', $parts);
    }

    /**
     * The size of the method and the longest its proof can take, computed from its structure and the
     * limits of the configuration, so it reads the same on every run: an upper bound, not a measure.
     * `$notTried` is the number of checks that the budget cut before they were tried.
     */
    public function forecast(CostPrediction $prediction, int $notTried): string
    {
        $parts = [];
        if ($notTried > 0) {
            $parts[] = sprintf('%d of the %d checks of this method %s not tried.', $notTried, $prediction->checks, $notTried === 1 ? 'was' : 'were');
        }
        $parts[] = sprintf(
            'Predicted worst case for this method: %s%s, from its structure (%d checks%s) and the limits, not a measure.',
            $this->duration($prediction->worstCaseMs),
            $prediction->capped ? ' (cut by contracts.methodBudgetMs)' : '',
            $prediction->checks,
            $prediction->unrolledIterations > 0 ? sprintf(', %d unrolled iterations', $prediction->unrolledIterations) : '',
        );
        $ways = ['a larger contracts.methodBudgetMs'];
        if ($prediction->nonLinear > 0) {
            $ways[] = 'simpler arithmetic (fewer products or divisions of variables)';
        }
        $parts[] = sprintf('Other ways out: %s.', $this->join($ways, 'or'));

        return implode(' ', $parts);
    }

    /**
     * The text of `contracts.costly`: what in the structure of the method makes Z3 likely to leave
     * checks undecided, and what to do. It holds no measured time.
     */
    public function costly(CostPrediction $prediction, Encoding $encoding): string
    {
        $loops = array_map(
            static fn (array $loop): string => sprintf('the foreach at line %d up to %d iterations%s', $loop['line'], $loop['bound'], $loop['depth'] > 0 ? ' (nested)' : ''),
            $prediction->loops,
        );
        $widest = array_reduce($encoding->unrolledLoops(), static fn (?LoopBound $best, LoopBound $loop): LoopBound => $best === null || $loop->iterations > $best->iterations ? $loop : $best);
        $way = $widest !== null && $widest->nameable && $widest->iterations > 1
            ? sprintf('a smaller bound with #[Requires(\'count(%s) <= %d\')]', $widest->subject, min(3, $widest->iterations - 1))
            : 'a smaller bound on the list it is built from with #[Requires]';

        return sprintf(
            'This method is likely to leave checks undecided (contracts.unknown): the proof unrolls %s, and the copies of the loop body hold %d divisions or modulos by a variable (%d non-linear operations in all), which Z3 decides only in part. '
            . 'Predicted worst case: %s, from the structure only and not a measure. Try %s, or simpler arithmetic, or a larger contracts.methodBudgetMs. '
            . 'This is a risk, not a failure: ignore it by identifier with ignoreErrors if the proof is known to pass.',
            $this->join($loops),
            $prediction->divisionsInLoops,
            $prediction->nonLinear,
            $this->duration($prediction->worstCaseMs),
            $way,
        );
    }

    private function duration(int $milliseconds): string
    {
        if ($milliseconds < 1000) {
            return $milliseconds . ' ms';
        }
        $seconds = intdiv($milliseconds + 999, 1000);
        if ($seconds <= 120) {
            return $seconds . ' s';
        }
        $minutes = intdiv($seconds + 59, 60);

        return $minutes <= 120 ? $minutes . ' min' : intdiv($minutes + 59, 60) . ' h';
    }

    /**
     * @param list<string> $items
     */
    private function join(array $items, string $conjunction = 'and'): string
    {
        if (count($items) < 2) {
            return implode('', $items);
        }
        $last = array_pop($items);

        return implode(', ', $items) . (count($items) > 1 ? ',' : '') . ' ' . $conjunction . ' ' . $last;
    }
}
