<?php

declare(strict_types=1);

namespace PhpstanContracts\Tests\Fixtures\Lookups;

/**
 * Each PHPDoc names a class through a form whose resolution reads that class.
 *
 * @mixin LookupMixed
 * @method static LookupMagic magic()
 */
final class LookupForms
{
    /**
     * @param value-of<LookupLevel> $level
     */
    public static function level(int $level): int
    {
        return $level;
    }

    /**
     * @param class-string<LookupNamed> $class
     * @return ($class is class-string<LookupNamed> ? LookupResult : null)
     */
    public static function named(string $class): ?LookupResult
    {
        return null;
    }
}
