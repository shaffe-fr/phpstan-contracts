<?php

declare(strict_types=1);

namespace PhpstanContracts\Solver;

/**
 * Builds the cases of a split of a check by the length of one list.
 *
 * The cases are a partition of every possible length: each of 0 to `$bound`, and one for the
 * lengths outside them. A length is an integer, so every model of the check satisfies exactly one
 * case. That is what makes the answers combine: the check is unsatisfiable when every case is, and
 * a model of one case is a model of the check.
 */
final class CaseSplit
{
    /** The most cases a split may have, the one for the lengths outside the bound included. */
    public const MAXIMUM_CASES = 12;

    /**
     * @param string $length the SMT term of the length of the list, such as `(List_Int_len l@1)`
     * @return list<SplitCase>|null null when the bound would need more than {@see self::MAXIMUM_CASES} cases
     */
    public static function cases(string $length, int $bound): ?array
    {
        if ($bound < 0 || $bound + 2 > self::MAXIMUM_CASES) {
            return null;
        }

        $cases = [];
        for ($size = 0; $size <= $bound; ++$size) {
            $cases[] = new SplitCase($size, sprintf('(assert (= %s %d))', $length, $size), (string) $size);
        }
        $cases[] = new SplitCase(null, sprintf('(assert (or (< %1$s 0) (> %1$s %2$d)))', $length, $bound), sprintf('outside 0..%d', $bound));

        return $cases;
    }
}
