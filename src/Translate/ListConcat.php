<?php

declare(strict_types=1);

namespace PhpstanContracts\Translate;

use PhpstanContracts\Smt\Smt;
use PhpstanContracts\Smt\Sort;
use PhpstanContracts\Smt\Term;

/**
 * `[...$a, ...$b]` for two lists of the same sort: PHP renumbers the keys of a spread list
 * from 0, so the result is the list of `count($a) + count($b)` elements, `$a` first.
 *
 * An SMT array has no concatenation, so the result is a fresh list that the axioms below
 * pin down in every cell that counts. They hold for any `$a` and `$b`, so they are asserted
 * unconditionally, like the definition of any other fresh constant.
 */
final class ListConcat
{
    public static function concat(Encoding $encoding, Term $first, Term $second): Term
    {
        $sort = $first->sort;
        $result = $encoding->fresh('spread', $sort);

        $firstLength = Smt::listLength($first);
        $resultLength = Smt::listLength($result);
        $encoding->assert(Smt::eq($resultLength, Smt::arithmetic('+', $firstLength, Smt::listLength($second))));

        $position = Smt::symbol('j!0', Sort::int());
        $cell = Smt::listItem($result, $position);
        $encoding->assert(Smt::forall(
            $position->smt,
            Smt::implies(
                Smt::all(Smt::compare('<=', Smt::int(0), $position), Smt::compare('<', $position, $firstLength)),
                Smt::eq($cell, Smt::listItem($first, $position)),
            ),
            [$cell],
        ));
        $encoding->assert(Smt::forall(
            $position->smt,
            Smt::implies(
                Smt::all(Smt::compare('<=', $firstLength, $position), Smt::compare('<', $position, $resultLength)),
                Smt::eq($cell, Smt::listItem($second, Smt::arithmetic('-', $position, $firstLength))),
            ),
            [$cell],
        ));

        return $result;
    }
}
