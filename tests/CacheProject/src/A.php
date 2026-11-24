<?php

declare(strict_types=1);

namespace CacheProject;

use PhpstanContracts\Attribute\Ensures;

/**
 * Each method reads code of another file that PHPStan does not see it depend on.
 */
final class A
{
    #[Ensures('$result === E::Old')]
    public static function old(): E
    {
        return E::Old;
    }

    #[Ensures('$result === Limits::MAX')]
    public static function limit(): int
    {
        return 10;
    }

    // `Rate` is not imported: it resolves to the namespace first, then to the global class.
    #[Ensures('$result === Rate::BASE')]
    public static function base(): int
    {
        return 5;
    }
}
