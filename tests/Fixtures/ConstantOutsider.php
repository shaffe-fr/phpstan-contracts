<?php

declare(strict_types=1);

namespace PhpstanContracts\Tests\Fixtures;

use PhpstanContracts\Attribute\Pure;

final class ConstantOutsider
{
    #[Pure]
    public static function protectedConstantOfAnotherClass(): int
    {
        return ConstantBase::GUARDED; // @phpstan-ignore classConstant.protected
    }
}
