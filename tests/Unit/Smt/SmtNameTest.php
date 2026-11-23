<?php

declare(strict_types=1);

namespace PhpstanContracts\Tests\Unit\Smt;

use PhpstanContracts\Smt\EnumSort;
use PhpstanContracts\Smt\RecordSort;
use PhpstanContracts\Smt\SmtName;
use PhpstanContracts\Smt\Sort;
use PHPUnit\Framework\TestCase;

final class SmtNameTest extends TestCase
{
    public function testNamesThatDifferOnlyByASeparatorStayApart(): void
    {
        self::assertSame('Order.Status', SmtName::of('Order\\Status'));
        self::assertSame('Order_Status', SmtName::of('Order_Status'));
        self::assertSame('Caf~C3~A9', SmtName::of('Café'));
    }

    public function testJoinedNamesStayApart(): void
    {
        $joined = new EnumSort('App\\A_B', 'E_' . SmtName::of('App\\A_B'), ['C' => null], null);
        $split = new EnumSort('App\\A', 'E_' . SmtName::of('App\\A'), ['B_C' => null], null);

        self::assertSame('C_App.A_B/C', $joined->constructorOf('C'));
        self::assertSame('C_App.A/B_C', $split->constructorOf('B_C'));
        self::assertNotSame($joined->valueFunction(), Sort::enum($split)->smt . '/value');
    }

    public function testFieldAccessorsStayApart(): void
    {
        $record = new RecordSort('App\\Box', 'R_' . SmtName::of('App\\Box'), ['size_x' => Sort::int()], ['size_x'], null);
        $other = new RecordSort('App\\Box_size', 'R_' . SmtName::of('App\\Box_size'), ['x' => Sort::int()], ['x'], null);

        self::assertNotSame($record->accessor('size_x'), $other->accessor('x'));
    }
}
