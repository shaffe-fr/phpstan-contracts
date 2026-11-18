<?php

declare(strict_types=1);

namespace PhpstanContracts\Tests\Fixtures;

use PhpstanContracts\Attribute\Ensures;
use PhpstanContracts\Attribute\Pure;
use PhpstanContracts\Attribute\Requires;

final class ContractFixture
{
    public static function anUnannotatedMethodIsNeverChecked(int $a, int $b): int
    {
        return $a * $b;
    }

    #[Ensures('$result === Suit::Hearts')]
    public static function shortNamesResolveAgainstTheSignature(): Suit
    {
        return Suit::Hearts;
    }

    #[Ensures('$result >=')]
    public static function syntaxErrorInAContract(): int
    {
        return 1;
    }

    #[Ensures('$a' . '> 0')]
    public static function aContractMustBeALiteral(int $a): int
    {
        return $a;
    }

    #[Ensures('$a; $a')]
    public static function aContractIsOneExpression(int $a): int
    {
        return $a;
    }

    #[Ensures('$nope > 0')]
    public static function unknownVariableInAContract(int $a): int
    {
        return $a;
    }

    #[Requires('$result > 0')]
    public static function resultIsNotAvailableInAPrecondition(int $a): int
    {
        return $a;
    }

    #[Ensures('$result === Missing::Case')]
    public static function unresolvableClassInAContract(): Suit
    {
        return Suit::Hearts;
    }

    #[Ensures('$result + 1')]
    public static function aContractMustBeBoolean(int $a): int
    {
        return $a;
    }

    #[Ensures('$result === Suit::Diamonds')]
    public static function unknownEnumCaseInAContract(): Suit
    {
        return Suit::Hearts;
    }

    #[Pure]
    public static function impliesOnlyExistsInContracts(bool $a, bool $b): bool
    {
        return implies($a, $b);
    }

    #[Pure]
    public static function parameterNamedResult(int $result): int
    {
        return $result;
    }

    #[Ensures('$result === $a')]
    public function instanceMethodsAreFineWithoutThis(int $a): int
    {
        return $a;
    }

    private int $state = 1;

    #[Pure]
    public function instanceMethodReadingThis(): int
    {
        return $this->state;
    }
}
