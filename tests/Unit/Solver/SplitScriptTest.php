<?php

declare(strict_types=1);

namespace PhpstanContracts\Tests\Unit\Solver;

use LogicException;
use PhpstanContracts\Smt\Smt;
use PhpstanContracts\Smt\Sort;
use PhpstanContracts\Solver\ScriptRenderer;
use PhpstanContracts\Solver\SplitCase;
use PhpstanContracts\Translate\Encoding;
use PhpstanContracts\Translate\EnsuresCheck;
use PhpstanContracts\Translate\Obligation;
use PhpstanContracts\Translate\ObligationKind;
use PHPUnit\Framework\TestCase;

final class SplitScriptTest extends TestCase
{
    public function testACaseGoesRightAfterTheCommandsAndBeforeTheEarlierObligationsAreAssumed(): void
    {
        $encoding = $this->encoding();
        $case = new SplitCase(3, '(assert (= (List_Int_len l@1) 3))', '3');

        $lines = explode("\n", trim((new ScriptRenderer())->renderSingleCheck($encoding, 2, $case)));

        $assertion = array_search($case->assertion, $lines, true);
        $assumed = array_search('(assert (> x@1 0))', $lines, true);
        $question = array_search('(assert (not (> x@1 1)))', $lines, true);
        self::assertIsInt($assertion);
        self::assertIsInt($assumed);
        self::assertIsInt($question);
        self::assertSame('(declare-const result@2 Int)', $lines[$assertion - 1], 'right after the commands');
        self::assertTrue($assertion < $assumed && $assumed < $question);
        self::assertNotContains('(push 1)', $lines, 'a script that never pushes is answered by the other solver');
    }

    public function testAScriptWithoutACaseIsTheScriptItWasBefore(): void
    {
        $encoding = $this->encoding();
        $renderer = new ScriptRenderer();
        $case = new SplitCase(0, '(assert (= (List_Int_len l@1) 0))', '0');

        $lines = explode("\n", $renderer->renderSingleCheck($encoding, 2));

        self::assertNotContains($case->assertion, $lines);
        self::assertSame(count($lines) + 1, count(explode("\n", $renderer->renderSingleCheck($encoding, 2, $case))), 'a case adds its assertion and nothing else');
    }

    public function testTheSatisfiabilityOfTheAssumptionsIsNotSplit(): void
    {
        $this->expectException(LogicException::class);
        (new ScriptRenderer())->renderSingleCheck($this->encoding(), 0, new SplitCase(0, '(assert true)', '0'));
    }

    private function encoding(): Encoding
    {
        $encoding = new Encoding();
        $x = $encoding->declareInput('x', Sort::int());
        $encoding->assert(Smt::compare('>', $x, Smt::int(-5)));
        $result = $encoding->declareResult(Sort::int());
        $encoding->addObligation(new Obligation(ObligationKind::DivisionByZero, 3, '', Smt::bool(true), Smt::compare('>', $x, Smt::int(0))));
        $encoding->ensures[] = new EnsuresCheck('$result > 1', 5, Smt::compare('>', $x, Smt::int(1)));
        unset($result);

        return $encoding;
    }
}
