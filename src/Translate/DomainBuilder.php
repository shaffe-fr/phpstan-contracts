<?php

declare(strict_types=1);

namespace PhpstanContracts\Translate;

use PhpstanContracts\Smt\Smt;
use PhpstanContracts\Smt\Sort;
use PhpstanContracts\Smt\SortKind;
use PhpstanContracts\Smt\Term;

/**
 * Builds the condition that a value belongs to the domain its PHPStan type describes.
 *
 * For an input it is an assumption of the proof. For the returned value it is a
 * goal: the method must not return something its own declared type excludes.
 */
final class DomainBuilder
{
    /**
     * No PHP array holds more elements: HT_MAX_SIZE is 2^30 on 64-bit builds.
     */
    public const MAX_LIST_LENGTH = 1073741824;

    /**
     * @param int $depth how many lists enclose the value, which names the bound variable of the next quantifier
     */
    public function constraint(VarSpec $spec, Term $term, bool $isInput, int $depth = 0): Term
    {
        return match ($spec->sort->kind) {
            SortKind::Int => $isInput || $spec->min !== null || $spec->max !== null
                ? Smt::inRange($term, $spec->min ?? PHP_INT_MIN, $spec->max ?? PHP_INT_MAX)
                : Smt::bool(true),
            SortKind::String => Smt::all(
                $isInput ? Smt::isByteString($term) : Smt::bool(true),
                $spec->nonEmpty ? Smt::compare('>', Smt::length($term), Smt::int(0)) : Smt::bool(true),
            ),
            SortKind::Nullable => $spec->inner === null
                ? Smt::bool(true)
                : Smt::implies(
                    Smt::not(Smt::isNone($term)),
                    $this->constraint($spec->inner, Smt::unwrap($term), $isInput, $depth),
                ),
            SortKind::Record => Smt::all(...array_map(
                fn (string $name, VarSpec $field): Term => $this->constraint($field, Smt::field($term, $name), $isInput, $depth),
                array_keys($spec->fields),
                array_values($spec->fields),
            )),
            SortKind::Shape => Smt::all(...array_map(
                fn (string $key, VarSpec $field): Term => $this->constraint($field, Smt::shapeField($term, $key), $isInput, $depth),
                array_map(strval(...), array_keys($spec->fields)),
                array_values($spec->fields),
            )),
            SortKind::List => $this->listConstraint($spec, $term, $isInput, $depth),
            default => Smt::bool(true),
        };
    }

    /**
     * A list has between 0 and 2^30 elements, at least one for a `non-empty-list`, and each
     * element is in the domain of its type: `forall k in [0, count): D(l[k])`, triggered by
     * the read of an element. The length bound is only assumed for an input; a built list
     * has the length its construction gives it.
     */
    private function listConstraint(VarSpec $spec, Term $term, bool $isInput, int $depth): Term
    {
        $length = Smt::listLength($term);
        $bounds = $isInput
            ? Smt::all(
                Smt::compare('>=', $length, Smt::int(0)),
                Smt::compare('<=', $length, Smt::int(self::MAX_LIST_LENGTH)),
            )
            : Smt::bool(true);
        $nonEmpty = $spec->nonEmpty ? Smt::compare('>=', $length, Smt::int(1)) : Smt::bool(true);

        $elements = Smt::bool(true);
        if ($spec->element !== null) {
            $variable = Smt::symbol('k!' . $depth, Sort::int());
            $item = Smt::listItem($term, $variable);
            $domain = $this->constraint($spec->element, $item, $isInput, $depth + 1);
            $elements = Smt::forall(
                $variable->smt,
                Smt::implies(Smt::hasKey($term, $variable), $domain),
                [$item],
            );
        }

        return Smt::all($bounds, $nonEmpty, $elements);
    }
}
