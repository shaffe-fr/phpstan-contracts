<?php

declare(strict_types=1);

namespace PhpstanContracts\Tests\Unit\Solver;

use PhpstanContracts\Smt\EnumSort;
use PhpstanContracts\Smt\Smt;
use PhpstanContracts\Smt\Sort;
use PhpstanContracts\Solver\ScriptRenderer;
use PhpstanContracts\Translate\Encoding;
use PhpstanContracts\Translate\EnsuresCheck;
use PhpstanContracts\Translate\Obligation;
use PhpstanContracts\Translate\ObligationKind;
use PHPUnit\Framework\TestCase;

final class ScriptRendererTest extends TestCase
{
    private ScriptRenderer $renderer;

    protected function setUp(): void
    {
        $this->renderer = new ScriptRenderer();
    }

    public function testTheScriptHasAFixedLayout(): void
    {
        $encoding = new Encoding();
        $x = $encoding->declareInput('x', Sort::int());
        $encoding->assert(Smt::inRange($x, 0, 10));
        $result = $encoding->declareResult(Sort::int());
        $encoding->recordReturn(Smt::bool(true), Smt::arithmetic('+', $x, Smt::int(1)), 7);
        $encoding->addObligation(new Obligation(
            ObligationKind::Overflow,
            7,
            '$x + 1',
            Smt::bool(true),
            Smt::inRange($result, -100, 100),
        ));
        $encoding->ensures[] = new EnsuresCheck('$result > 0', 5, Smt::compare('>', $result, Smt::int(0)));

        $script = $this->renderer->render($encoding);

        self::assertSame(3, $this->renderer->checkCount($encoding), 'assumptions, one obligation, one postcondition');
        self::assertSame(
            [
                '(declare-const x@1 Int)',
                '(assert (and (>= x@1 0) (<= x@1 10)))',
                '(declare-const result@2 Int)',
                '(assert (= result@2 (+ x@1 1)))',
                '(declare-const return@3 Bool)',
                '(assert return@3)',
                '(echo "@@0")',
                '(check-sat)',
                '(echo "@@1")',
                '(push 1)',
                '(assert (not (and (>= result@2 (- 100)) (<= result@2 100))))',
                '(check-sat)',
                '(get-value (x@1 result@2 return@3))',
                '(pop 1)',
                '(assert (and (>= result@2 (- 100)) (<= result@2 100)))',
                '(echo "@@2")',
                '(push 1)',
                '(assert (not (> result@2 0)))',
                '(check-sat)',
                '(get-value (x@1 result@2 return@3))',
                '(pop 1)',
            ],
            $this->afterThePrelude($script),
        );
    }

    public function testTheAssumptionsAreCheckedBeforeTheBodyIsDefined(): void
    {
        $encoding = new Encoding();
        $x = $encoding->declareInput('x', Sort::int());
        $encoding->assert(Smt::compare('>', $x, Smt::int(0)));
        $encoding->endAssumptions();
        $encoding->define('t', Smt::arithmetic('*', $x, $x));

        $lines = $this->afterThePrelude($this->renderer->render($encoding));

        self::assertSame(
            [
                '(declare-const x@1 Int)',
                '(assert (> x@1 0))',
                '(echo "@@0")',
                '(check-sat)',
                '(declare-const t@2 Int)',
                '(assert (= t@2 (* x@1 x@1)))',
            ],
            $lines,
            'the body only defines values from the inputs, so it cannot make the assumptions unsatisfiable',
        );
    }

    public function testAnObligationIsAssumedByTheChecksThatFollowIt(): void
    {
        $encoding = new Encoding();
        $x = $encoding->declareInput('x', Sort::int());
        $encoding->declareResult(Sort::int());
        $guard = $encoding->fresh('guard', Sort::bool());

        $encoding->addObligation(new Obligation(ObligationKind::DivisionByZero, 3, '', $guard, Smt::compare('>', $x, Smt::int(0))));

        $lines = $this->afterThePrelude($this->renderer->render($encoding));

        self::assertContains('(assert (and guard@3 (not (> x@1 0))))', $lines, 'the check looks for a violation under the path');
        self::assertContains('(assert (or (not guard@3) (> x@1 0)))', $lines, 'then the obligation is assumed afterwards');
        self::assertGreaterThan(
            array_search('(pop 1)', $lines, true),
            array_search('(assert (or (not guard@3) (> x@1 0)))', $lines, true),
            'the assumption comes after the check that it came from',
        );
    }

    public function testEveryCheckIsIntroducedByAMarkerInOrder(): void
    {
        $encoding = new Encoding();
        $encoding->declareResult(Sort::int());
        $encoding->ensures[] = new EnsuresCheck('a', 1, Smt::bool(true));
        $encoding->ensures[] = new EnsuresCheck('b', 2, Smt::bool(false));

        $script = $this->renderer->render($encoding);

        preg_match_all('/^\(echo "@@(\d+)"\)$/m', $script, $markers);

        self::assertSame(['0', '1', '2'], $markers[1]);
        self::assertSame(1, substr_count($script, '(check-sat)', 0, (int) strpos($script, '(echo "@@1")')), 'the first check has no push');
    }

    public function testTheReturnTypeCheckComesLast(): void
    {
        $encoding = new Encoding();
        $result = $encoding->declareResult(Sort::int());
        $encoding->ensures[] = new EnsuresCheck('ensures', 1, Smt::compare('>=', $result, Smt::int(0)));
        $encoding->returnTypeCheck = new Obligation(ObligationKind::ReturnType, 2, 'int<0, 5>', Smt::bool(true), Smt::inRange($result, 0, 5));

        $script = $this->renderer->render($encoding);

        self::assertSame(3, $this->renderer->checkCount($encoding));
        self::assertGreaterThan(
            (int) strpos($script, '(assert (not (>= result@1 0)))'),
            (int) strpos($script, '(assert (not (and (>= result@1 0) (<= result@1 5))))'),
        );
        self::assertStringNotContainsString('(assert (or (not true)', $script, 'the return type is never assumed');
    }

    public function testEnumsAndNullablesAreDeclaredBeforeTheyAreUsed(): void
    {
        $enum = Sort::enum(new EnumSort('App\\Level', 'E_App_Level', ['Low' => 1, 'High' => 10], 'int'));
        $encoding = new Encoding();
        $encoding->declareInput('level', Sort::nullable($enum));
        $encoding->declareResult(Sort::bool());

        $script = $this->renderer->render($encoding);

        self::assertStringContainsString('(declare-datatypes ((E_App_Level 0)) (((C_App_Level/Low) (C_App_Level/High))))', $script);
        self::assertStringContainsString('(declare-datatypes ((Opt_E_App_Level 0)) (((none_Opt_E_App_Level) (some_Opt_E_App_Level (val_Opt_E_App_Level E_App_Level)))))', $script);
        self::assertLessThan(
            (int) strpos($script, '(declare-datatypes ((Opt_E_App_Level 0))'),
            (int) strpos($script, '(declare-datatypes ((E_App_Level 0))'),
        );
        self::assertLessThan((int) strpos($script, '(declare-const level@1'), (int) strpos($script, '(declare-datatypes ((Opt_E_App_Level 0))'));
    }

    public function testEnumFunctionsAreDefinedOnlyWhenTheyAreUsed(): void
    {
        $enum = new EnumSort('App\\Level', 'E_App_Level', ['Low' => 1, 'High' => 10], 'int');
        $encoding = new Encoding();
        $encoding->declareInput('level', Sort::enum($enum));
        $encoding->declareResult(Sort::int());

        self::assertStringNotContainsString('E_App_Level/value', $this->renderer->render($encoding));

        $encoding->requireEnumFunction($enum, 'value');
        $encoding->requireEnumFunction($enum, 'name');
        $script = $this->renderer->render($encoding);

        self::assertStringContainsString('(define-fun E_App_Level/value ((e E_App_Level)) Int (ite (= e C_App_Level/Low) 1 10))', $script);
        self::assertStringContainsString('(define-fun E_App_Level/name ((e E_App_Level)) String (ite (= e C_App_Level/Low) "Low" "High"))', $script);
    }

    public function testStringBackedEnumsReturnStrings(): void
    {
        $enum = new EnumSort('App\\Code', 'E_App_Code', ['Eur' => 'EUR', 'Usd' => 'USD'], 'string');
        $encoding = new Encoding();
        $encoding->declareInput('code', Sort::enum($enum));
        $encoding->declareResult(Sort::string());
        $encoding->requireEnumFunction($enum, 'value');

        self::assertStringContainsString(
            '(define-fun E_App_Code/value ((e E_App_Code)) String (ite (= e C_App_Code/Eur) "EUR" "USD"))',
            $this->renderer->render($encoding),
        );
    }

    public function testTheSumOfAListIsDeclaredOnlyWhenItIsUsed(): void
    {
        $encoding = new Encoding();
        $encoding->declareInput('items', Sort::list(Sort::int()));

        self::assertStringNotContainsString('sum_List_Int', $this->renderer->render($encoding));

        $encoding->requireListSum();
        $encoding->requireListSum();
        $script = $this->renderer->render($encoding);

        self::assertSame(1, substr_count($script, '(define-fun-rec sum_List_Int '));
        self::assertLessThan(
            strpos($script, '(define-fun-rec sum_List_Int '),
            strpos($script, '(declare-datatypes ((List_Int 0))'),
            'the datatype comes first',
        );
    }

    public function testThePreludeDefinesPhpDivisionAndModulo(): void
    {
        $script = $this->renderer->render((new Encoding()));

        self::assertStringContainsString('(define-fun php_intdiv', $script);
        self::assertStringContainsString('(define-fun php_mod', $script);
        self::assertStringStartsWith("(set-option :produce-models true)\n", $script);
    }

    public function testABoundedCheckKeepsTheListsShortAndAssumesTheEarlierObligations(): void
    {
        $list = Sort::list(Sort::int());
        $encoding = new Encoding();
        $items = $encoding->declareInput('items', $list);
        $encoding->declareResult(Sort::int());
        $encoding->addObligation(new Obligation(ObligationKind::UndefinedKey, 3, '$items[0]', Smt::bool(true), Smt::compare('>', Smt::listLength($items), Smt::int(0))));
        $encoding->ensures[] = new EnsuresCheck('count($items) < 3', 5, Smt::compare('<', Smt::listLength($items), Smt::int(3)));

        $script = $this->renderer->renderBoundedCheck($encoding, 2, 10);
        $lines = $this->afterThePrelude($script);

        self::assertSame(1, $this->renderer->checkCount($encoding) - 2, 'the main script has the assumptions, the obligation and the postcondition');
        self::assertContains('(assert (> (List_Int_len items@1) 0))', $lines, 'the obligation that came first is assumed');
        self::assertContains('(assert (not (< (List_Int_len items@1) 3)))', $lines, 'the violation of the check is asserted');
        self::assertContains('(assert (<= (List_Int_len items@1) 10))', $lines, 'every probed length is bounded');
        self::assertSame(1, substr_count($script, '(check-sat)'));
        self::assertSame(1, substr_count($script, '(echo "@@0")'), 'the answer is read as check 0');
        self::assertStringNotContainsString('(push', $script);
        self::assertSame(
            '(check-sat)',
            $lines[array_search('(assert (<= (List_Int_len items@1) 10))', $lines, true) + 1],
            'the bounds come before the question',
        );
    }

    public function testABoundedCheckOfTheFirstObligationAssumesNoObligation(): void
    {
        $encoding = new Encoding();
        $x = $encoding->declareInput('x', Sort::int());
        $encoding->addObligation(new Obligation(ObligationKind::Overflow, 3, '', Smt::bool(true), Smt::compare('>', $x, Smt::int(0))));
        $encoding->addObligation(new Obligation(ObligationKind::Overflow, 4, '', Smt::bool(true), Smt::compare('<', $x, Smt::int(9))));

        $first = $this->afterThePrelude($this->renderer->renderBoundedCheck($encoding, 1, 10));
        $second = $this->afterThePrelude($this->renderer->renderBoundedCheck($encoding, 2, 10));

        self::assertNotContains('(assert (> x@1 0))', $first);
        self::assertContains('(assert (not (> x@1 0)))', $first);
        self::assertContains('(assert (> x@1 0))', $second);
        self::assertContains('(assert (not (< x@1 9)))', $second);
    }

    public function testABoundedCheckThatDoesNotExistIsRefused(): void
    {
        $encoding = new Encoding();
        $encoding->declareInput('x', Sort::int());

        $this->expectException(\LogicException::class);
        $this->renderer->renderBoundedCheck($encoding, 1, 10);
    }

    /**
     * @return list<string> the lines that follow the fixed prelude
     */
    private function afterThePrelude(string $script): array
    {
        $lines = explode("\n", rtrim($script, "\n"));
        foreach ($lines as $index => $line) {
            if (str_starts_with($line, '(declare-const') || str_starts_with($line, '(echo')) {
                return array_slice($lines, $index);
            }
        }

        self::fail('The script has no declaration.');
    }
}
