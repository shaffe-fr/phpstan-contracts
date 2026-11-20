<?php

declare(strict_types=1);

namespace PhpstanContracts\Translate;

use PhpstanContracts\Smt\Smt;
use PhpstanContracts\Smt\Sort;
use PhpstanContracts\Smt\Term;

/**
 * The `return` statements of an inlined method: each one is the value of the call
 * on the paths that reach it, and a `return` ends its path, so no two overlap.
 */
final class InlinedReturns
{
    /** @var list<array{Term, Term}> */
    private array $returns = [];

    public function add(Term $reached, Term $value): void
    {
        $this->returns[] = [$reached, $value];
    }

    public function isEmpty(): bool
    {
        return $this->returns === [];
    }

    public function value(Sort $sort): Term
    {
        $returns = $this->returns;
        $last = array_pop($returns);
        $value = $last === null ? throw new \LogicException('No return to merge.') : $last[1];

        foreach (array_reverse($returns) as [$reached, $returned]) {
            $value = Smt::ite($reached, $returned, $value);
        }

        return $value->sort->equals($sort) ? $value : throw new \LogicException('Return sort mismatch.');
    }
}
