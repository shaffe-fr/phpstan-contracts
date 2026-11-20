<?php

declare(strict_types=1);

namespace PhpstanContracts\Tests\Fixtures;

use PhpstanContracts\Attribute\Ensures;
use PhpstanContracts\Attribute\Pure;

/**
 * Enum methods that call each other, and in_array() over cases.
 */
enum Role: string
{
    case Administrator = 'admin';
    case Validator = 'validator';
    case Prescriber = 'prescriber';
    case Viewer = 'viewer';

    public const VALIDATORS = [self::Administrator, self::Validator];

    #[Ensures('$result >= 1 && $result <= 4')]
    public function hierarchyLevel(): int
    {
        return match ($this) {
            self::Administrator => 1,
            self::Validator => 2,
            self::Prescriber => 3,
            self::Viewer => 4,
        };
    }

    #[Ensures('implies($this === self::Administrator, $result)')]
    #[Ensures('implies($this === $other, $result)')]
    #[Ensures('implies($other === self::Administrator, $result === ($this === self::Administrator))')]
    public function canManage(Role $other): bool
    {
        return $this->hierarchyLevel() <= $other->hierarchyLevel();
    }

    #[Ensures('implies($this === $other, $result)')]
    public function canManageBroken(Role $other): bool
    {
        return $this->hierarchyLevel() < $other->hierarchyLevel();
    }

    #[Ensures('$result === ($this === self::Administrator || $this === self::Validator)')]
    public function canValidate(): bool
    {
        return in_array($this, [self::Administrator, self::Validator]);
    }

    #[Ensures('$result === ($this === self::Administrator || $this === self::Validator)')]
    public function canValidateFromAConstant(): bool
    {
        return in_array($this, self::VALIDATORS, true);
    }

    #[Ensures('implies($this === self::Validator, $result)')]
    public function canPrescribeBroken(): bool
    {
        return in_array($this, [self::Administrator, self::Prescriber], true);
    }

    #[Pure]
    public function callsAStaticMethodOnAnInstance(): bool
    {
        return self::canManage($this);
    }
}
