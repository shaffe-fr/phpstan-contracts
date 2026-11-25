<?php

declare(strict_types=1);

namespace PhpstanContracts\Tests\Fixtures;

use PhpstanContracts\Attribute\Ensures;
use PhpstanContracts\Attribute\Requires;

/**
 * A final class without any instance property: `$this` has no state, so it is the
 * receiver of calls that are inlined and nothing else.
 */
final class StatelessGuard
{
    private const int LIMIT = 100;

    private static int $calls = 0;

    /**
     * @param int<0, 1000> $amount
     */
    #[Ensures('$result === ($amount <= 100)')]
    public function withinLimit(int $amount): bool
    {
        return $this->isUnder($amount, self::LIMIT);
    }

    /**
     * @param int<0, 1000> $amount
     */
    #[Ensures('$result >= 0')]
    #[Ensures('$result <= 100')]
    public function clamped(int $amount): int
    {
        return $this->capped($this->floored($amount));
    }

    /**
     * @param int<0, 1000> $amount
     */
    #[Ensures('$result >= 0')]
    #[Ensures('$result <= 99')]
    public function clampedBroken(int $amount): int
    {
        return $this->capped($this->floored($amount));
    }

    #[Requires('$amount >= 0')]
    #[Ensures('$result >= 0')]
    public function halved(int $amount): int
    {
        return $this->half($amount);
    }

    #[Ensures('$result >= 0')]
    public function halvedWithoutPrecondition(int $amount): int
    {
        return $this->half($amount);
    }

    #[Ensures('$result === true')]
    public function comparesThisToItself(): bool
    {
        return $this === $this;
    }

    #[Ensures('$result >= 0')]
    public function passesThisAsAnArgument(): int
    {
        return self::measure($this);
    }

    #[Ensures('$result >= 0')]
    public function readsAProperty(): int
    {
        return $this->missing;
    }

    private static function measure(self $guard): int
    {
        return 1;
    }

    private function isUnder(int $amount, int $limit): bool
    {
        return $amount <= $limit;
    }

    private function floored(int $amount): int
    {
        return max(0, $amount);
    }

    private function capped(int $amount): int
    {
        return min(self::LIMIT, $amount);
    }

    #[Requires('$amount >= 0')]
    private function half(int $amount): int
    {
        return intdiv($amount, 2);
    }
}
