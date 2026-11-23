<?php

declare(strict_types=1);

namespace PhpstanContracts\Translate;

use PhpstanContracts\Smt\Sort;
use PhpstanContracts\Smt\SortKind;

/**
 * Chooses the extra terms to ask Z3 for so that a counterexample can print the lists in it.
 *
 * The model of a list is `(mk_List_Int 3 <array>)`, and the array is a Z3 term (a constant
 * array, a `store` chain, a `lambda`) that this library does not interpret. Z3 answers a
 * term it is asked for verbatim, so instead of reading the array, the length and the first
 * {@see self::MAX_ELEMENTS} elements of every list reachable from a symbol are asked for
 * as terms: `(List_Int_len l@1)` and `(select (List_Int_items l@1) 0)`.
 */
final class ProbePlanner
{
    public const MAX_ELEMENTS = 10;

    /** Lists inside the elements of a list are followed this deep and no further, which bounds the number of probes. */
    private const MAX_DEPTH = 2;

    /**
     * @return list<string> the probe terms for the lists inside `$term`, not the term itself
     */
    public function probes(string $term, Sort $sort, int $depth = 0): array
    {
        switch ($sort->kind) {
            case SortKind::List:
                if ($sort->element === null || $depth >= self::MAX_DEPTH) {
                    return [];
                }
                $probes = [self::length($term, $sort)];
                for ($index = 0; $index < self::MAX_ELEMENTS; ++$index) {
                    $element = self::item($term, $sort, $index);
                    $probes[] = $element;
                    array_push($probes, ...$this->probes($element, $sort->element, $depth + 1));
                }

                return $probes;
            case SortKind::Shape:
                $probes = [];
                foreach (array_values($sort->shape->fields ?? []) as $position => $field) {
                    if (self::containsList($field)) {
                        array_push($probes, ...$this->probes(sprintf('(%s %s)', $sort->shape?->accessorAt($position), $term), $field, $depth));
                    }
                }

                return $probes;
            case SortKind::Record:
                $probes = [];
                foreach ($sort->record->fields ?? [] as $name => $field) {
                    if (self::containsList($field)) {
                        array_push($probes, ...$this->probes(sprintf('(%s %s)', $sort->record?->accessor((string) $name), $term), $field, $depth));
                    }
                }

                return $probes;
            case SortKind::Nullable:
                return $sort->inner !== null && self::containsList($sort->inner)
                    ? $this->probes(self::payload($term, $sort), $sort->inner, $depth)
                    : [];
            default:
                return [];
        }
    }

    public static function length(string $term, Sort $list): string
    {
        return sprintf('(%s_len %s)', $list->smt, $term);
    }

    public static function isLength(string $term): bool
    {
        return preg_match('/^\(List_\w+_len /', $term) === 1;
    }

    public static function item(string $term, Sort $list, int $index): string
    {
        return sprintf('(select (%s_items %s) %d)', $list->smt, $term, $index);
    }

    public static function payload(string $term, Sort $nullable): string
    {
        return sprintf('(val_%s %s)', $nullable->smt, $term);
    }

    private static function containsList(Sort $sort): bool
    {
        return match ($sort->kind) {
            SortKind::List => true,
            SortKind::Nullable => $sort->inner !== null && self::containsList($sort->inner),
            SortKind::Shape => array_reduce($sort->shape->fields ?? [], static fn (bool $found, Sort $field): bool => $found || self::containsList($field), false),
            SortKind::Record => array_reduce($sort->record->fields ?? [], static fn (bool $found, Sort $field): bool => $found || self::containsList($field), false),
            default => false,
        };
    }
}
