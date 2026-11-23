<?php

declare(strict_types=1);

namespace PhpstanContracts\Tests\Fixtures;

use PhpstanContracts\Attribute\Ensures;

/**
 * PHP converts an int to a float for a native `float` type only. PHPStan lets a caller
 * pass an int where the PHPDoc says `float`, and that int stays an int.
 */
final class PhpDocFloat
{
    /**
     * @param float $x
     */
    #[Ensures('implies($x == 1, $result === true)')]
    public static function untypedParameter($x): bool
    {
        return $x === 1.0;
    }

    /**
     * @param float $x
     */
    #[Ensures('implies($x == 1, $result === true)')]
    public static function mixedParameter(mixed $x): bool
    {
        return $x === 1.0;
    }

    #[Ensures('implies($measure->amount == 1, $result === true)')]
    public static function mixedField(MixedMeasure $measure): bool
    {
        return $measure->amount === 1.0;
    }

    /**
     * @param ?float $x
     */
    #[Ensures('implies($x !== null && $x == 1, $result === true)')]
    public static function nativeNullableFloat(?float $x): bool
    {
        return $x === 1.0;
    }

    #[Ensures('implies($x == 1, $result === true)')]
    public static function nativeFloat(float $x): bool
    {
        return $x === 1.0;
    }
}

final readonly class MixedMeasure
{
    public function __construct(
        /** @var float */
        public mixed $amount,
    ) {
    }
}
