<?php

declare(strict_types=1);

namespace CacheProject;

final class B
{
    private const STEP = 7;

    public static function same(int $value): int
    {
        return $value;
    }

    public static function step(): int
    {
        return self::STEP;
    }
}
