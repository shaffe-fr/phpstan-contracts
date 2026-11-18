<?php

declare(strict_types=1);

namespace PhpstanContracts\Examples\RealWorld;

use PhpstanContracts\Attribute\Ensures;
use PhpstanContracts\Attribute\Requires;

final class Pagination
{
    /**
     * Number of pages needed to display $totalItems, $perPage at a time.
     *
     * @param int<0, 100000000> $totalItems
     * @param int<1, 1000> $perPage
     */
    #[Ensures('$result >= 0')]
    #[Ensures('$result * $perPage >= $totalItems')]
    #[Ensures('$totalItems === 0 || ($result - 1) * $perPage < $totalItems')]
    public static function pageCount(int $totalItems, int $perPage): int
    {
        return intdiv($totalItems + $perPage - 1, $perPage);
    }

    /**
     * The usual off-by-one: when $totalItems is an exact multiple of $perPage,
     * this reports one empty page too many.
     *
     * @param int<0, 100000000> $totalItems
     * @param int<1, 1000> $perPage
     */
    #[Ensures('$totalItems === 0 || ($result - 1) * $perPage < $totalItems')]
    public static function pageCountBroken(int $totalItems, int $perPage): int
    {
        return intdiv($totalItems, $perPage) + 1;
    }

    /**
     * Keeps a user-supplied ?page=N inside the existing pages.
     *
     * @param int<-1000000, 1000000> $requestedPage
     * @param int<1, 1000000> $pageCount
     */
    #[Ensures('$result >= 1 && $result <= $pageCount')]
    #[Ensures('implies($requestedPage >= 1 && $requestedPage <= $pageCount, $result === $requestedPage)')]
    public static function clampPage(int $requestedPage, int $pageCount): int
    {
        return min(max($requestedPage, 1), $pageCount);
    }

    /**
     * @param int<1, 1000000> $page
     * @param int<1, 1000> $perPage
     */
    #[Ensures('$result >= 0')]
    #[Ensures('$result === $perPage * ($page - 1)')]
    #[Ensures('implies($page === 1, $result === 0)')]
    #[Ensures('implies($page >= 2, $result >= $perPage)')]
    public static function offset(int $page, int $perPage): int
    {
        return ($page - 1) * $perPage;
    }
}
