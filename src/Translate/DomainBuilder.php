<?php

declare(strict_types=1);

namespace PhpstanContracts\Translate;

use PhpstanContracts\Smt\Smt;
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
    public function constraint(VarSpec $spec, Term $term, bool $isInput): Term
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
                    $this->constraint($spec->inner, Smt::unwrap($term), $isInput),
                ),
            SortKind::Record => Smt::all(...array_map(
                fn (string $name, VarSpec $field): Term => $this->constraint($field, Smt::field($term, $name), $isInput),
                array_keys($spec->fields),
                array_values($spec->fields),
            )),
            default => Smt::bool(true),
        };
    }
}
