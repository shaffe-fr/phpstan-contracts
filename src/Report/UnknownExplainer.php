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
     * @param list<int> $undecidedLengths the lengths of the iterated list that a split by length did not decide
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

        $first = $loops === [] ? null : reset($loops);
        if ($undecidedLengths !== [] && $first !== null) {
            $parts[] = sprintf(
                'The split of the proof by the length of %s did not decide the lengths %s.',
                $first->subject,
                $this->join(array_map(strval(...), $undecidedLengths)),
            );
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
