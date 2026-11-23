<?php

declare(strict_types=1);

namespace PhpstanContracts\Examples\RealWorld;

use PhpstanContracts\Attribute\Ensures;

/**
 * Permissions written as enum methods that call each other and test membership
 * with in_array().
 */
enum UserRole: string
{
    case Administrator = 'administrator';
    case Validator = 'validator';
    case Prescriber = 'prescriber';
    case Viewer = 'viewer';

    private const PRESCRIBERS = [self::Administrator, self::Prescriber];

    /**
     * 1 is the highest level.
     */
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

    /**
     * A role manages the roles at its level and below. The call to hierarchyLevel()
     * is translated with its body, so its result is known exactly.
     */
    #[Ensures('implies($this === self::Administrator, $result)')]
    #[Ensures('implies($this === $other, $result)')]
    #[Ensures('implies($other === self::Administrator, $result === ($this === self::Administrator))')]
    public function canManage(UserRole $other): bool
    {
        return $this->hierarchyLevel() <= $other->hierarchyLevel();
    }

    /**
     * `<` instead of `<=`: a role no longer manages its own level.
     */
    #[Ensures('implies($this === $other, $result)')]
    public function canManageBroken(UserRole $other): bool
    {
        return $this->hierarchyLevel() < $other->hierarchyLevel();
    }

    #[Ensures('$result === ($this === self::Administrator || $this === self::Validator)')]
    public function canValidate(): bool
    {
        return in_array($this, [self::Administrator, self::Validator]);
    }

    /**
     * A validator can prescribe too, but the list in the constant forgets it.
     */
    #[Ensures('implies($this === self::Validator, $result)')]
    public function canPrescribeBroken(): bool
    {
        return in_array($this, self::PRESCRIBERS, true);
    }
}
