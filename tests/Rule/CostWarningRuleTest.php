<?php

declare(strict_types=1);

namespace PhpstanContracts\Tests\Rule;

use PhpstanContracts\Examples\RealWorld\DiscountSplit;
use PhpstanContracts\Examples\RealWorld\Invoice;
use PhpstanContracts\Examples\RealWorld\OrderTotals;
use PhpstanContracts\Examples\Pricing;
use PhpstanContracts\Examples\RealWorld\ProportionalSplit;
use PhpstanContracts\Tests\Fixtures\CostFixture;
use PhpstanContracts\Tests\Support\RuleFixtureTestCase;

/**
 * `contracts.costly` reads the structure of a method and never the clock, so it is the same on every run
 * and in every cached file. The budget of the method is 1 ms here: Z3 never gets to answer, and the tests
 * look only at the diagnostics of the structure and at the timing report.
 */
final class CostWarningRuleTest extends RuleFixtureTestCase
{
    protected static function configFile(): string
    {
        return 'cost-report.neon';
    }

    public function testTheProportionalSplitIsFlaggedFromFiveLinesAndNotBefore(): void
    {
        $diagnostics = $this->diagnose([CostFixture::class])[CostFixture::class];

        self::assertSame(['split5', 'split8'], $this->costly($diagnostics));
        $message = $diagnostics['split8'][0]->message;
        foreach ($diagnostics['split8'] as $diagnostic) {
            if ($diagnostic->identifier === 'contracts.costly') {
                $message = $diagnostic->message;
            }
        }
        self::assertStringContainsString('the proof unrolls the foreach at line ', $message);
        self::assertStringContainsString('up to 8 iterations', $message);
        self::assertStringContainsString('8 divisions or modulos by a variable', $message);
        self::assertStringContainsString('Predicted worst case: ', $message);
        self::assertStringContainsString('not a measure', $message);
        self::assertStringContainsString('#[Requires(\'count($lineTotals) <= 3\')]', $message);
        self::assertStringContainsString('ignore it by identifier with ignoreErrors', $message);
    }

    public function testProductsInALoopAndArithmeticWithoutALoopAreNotFlagged(): void
    {
        $diagnostics = $this->diagnose([CostFixture::class])[CostFixture::class];

        self::assertNotContains('productsOnly', $this->costly($diagnostics));
        self::assertNotContains('withoutLoop', $this->costly($diagnostics));
    }

    public function testTheExamplesThatAreProvenFastAreNotFlagged(): void
    {
        $classes = [ProportionalSplit::class, OrderTotals::class, DiscountSplit::class, Invoice::class, Pricing::class];
        foreach ($this->diagnose($classes) as $class => $methods) {
            self::assertSame([], $this->costly($methods), $class);
        }
    }

    public function testTheTimingReportHoldsOneValidLinePerMethodWithTheStructureAndTheMeasuredTime(): void
    {
        $path = $this->reportPath();
        if (is_file($path)) {
            unlink($path);
        }

        $this->diagnose([CostFixture::class]);

        $lines = file($path, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
        self::assertIsArray($lines);
        $records = [];
        foreach ($lines as $line) {
            $record = json_decode($line, true, 512, JSON_THROW_ON_ERROR);
            self::assertIsArray($record);
            $records[(string) $record['method']] = $record;
        }
        // A killed Z3 can fail a method, and a failed method has no line: the ones that are there are checked.
        self::assertNotSame([], $records);
        self::assertSame(array_keys($records), array_unique(array_keys($records)));
        foreach ($records as $method => $record) {
            self::assertStringStartsWith(CostFixture::class . '::', $method);
            self::assertStringEndsWith('CostFixture.php', (string) $record['file']);
            self::assertSame(['checks', 'loops', 'unrolledIterations', 'nonLinear', 'divisionsInLoops', 'cases', 'worstCaseMs', 'capped'], array_keys($record['predicted']));
            self::assertSame(['main', 'retry', 'split'], array_keys($record['measuredMs']));
            self::assertSame(['sat', 'unsat', 'unknown', 'notTried', 'remembered'], array_keys($record['verdicts']));
            self::assertSame('exhausted', $record['budget'], 'a budget of 1 ms is spent by any run');
            self::assertSame(1, $record['predicted']['worstCaseMs'], 'the worst case is cut by the budget');
            self::assertTrue($record['predicted']['capped']);
        }

        $split8 = $records[CostFixture::class . '::split8'] ?? null;
        if ($split8 !== null) {
            self::assertSame(8, $split8['predicted']['divisionsInLoops']);
            self::assertSame(8, $split8['predicted']['unrolledIterations']);
            self::assertSame(10, $split8['predicted']['cases']);
            self::assertSame(8, $split8['predicted']['loops'][0]['bound']);
            self::assertSame(0, $split8['predicted']['loops'][0]['depth']);
        }
    }

    /**
     * @param array<string, list<\PhpstanContracts\Tests\Support\Diagnostic>> $methods
     * @return list<string> the methods with a `contracts.costly`, in order
     */
    private function costly(array $methods): array
    {
        $costly = [];
        foreach ($methods as $method => $diagnostics) {
            foreach ($diagnostics as $diagnostic) {
                if ($diagnostic->identifier === 'contracts.costly') {
                    $costly[] = $method;
                    break;
                }
            }
        }
        sort($costly);

        return $costly;
    }

    private function reportPath(): string
    {
        return self::getContainer()->getParameter('tmpDir') . '/contracts-timing/report.jsonl';
    }
}
