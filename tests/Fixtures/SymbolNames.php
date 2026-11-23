<?php

declare(strict_types=1);

namespace PhpstanContracts\Tests\Fixtures\Symbols\Order;

enum Status: int
{
    case Open = 1;
    case Closed = 2;
}

namespace PhpstanContracts\Tests\Fixtures\Symbols;

/** The name a PEAR-style codebase gives the enum above, next to it. */
enum Order_Status: int
{
    case Open = 10;
    case Closed = 20;
}

enum A
{
    case B_C;
    case D;
}

enum A_B
{
    case C;
    case E;
}

namespace PhpstanContracts\Tests\Fixtures;

use PhpstanContracts\Attribute\Ensures;

/**
 * Different PHP names must give different SMT symbols, whatever characters they share.
 */
final class SymbolNames
{
    /**
     * Status::Open and Order_Status::Open are 1 and 10, so the sum can be 11.
     */
    #[Ensures('$result >= 20')]
    public static function sumOfTwoEnumsWithCloseNames(Symbols\Order\Status $modern, Symbols\Order_Status $legacy): int
    {
        return $modern->value + $legacy->value;
    }

    /**
     * A::B_C and A_B::C are two cases of two enums: both matches are exhaustive.
     */
    #[Ensures('$result === 3')]
    public static function casesWhoseNamesJoinTheSameWay(Symbols\A $a, Symbols\A_B $b): int
    {
        $x = match ($a) {
            Symbols\A::B_C => 1,
            Symbols\A::D => 2,
        };
        $y = match ($b) {
            Symbols\A_B::C => 2,
            Symbols\A_B::E => 1,
        };

        return $x + $y;
    }
}
