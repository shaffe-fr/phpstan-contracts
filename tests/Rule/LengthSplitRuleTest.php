<?php

declare(strict_types=1);

namespace PhpstanContracts\Tests\Rule;

use PhpstanContracts\Tests\Fixtures\SplitProvenFixture;
use PhpstanContracts\Tests\Fixtures\SplitRefutedFixture;
use PhpstanContracts\Tests\Support\Diagnostic;
use PhpstanContracts\Tests\Support\RuleFixtureTestCase;
use ReflectionMethod;

/**
 * A check that Z3 leaves `unknown`, and that the retry leaves `unknown` too, is asked once per length
 * of the list that drives the unrolling. The time limit is far above what each case needs, so a
 * slower runner decides them too. The values of a counterexample are not pinned: Z3 prints other
 * valid models on other platforms.
 */
final class LengthSplitRuleTest extends RuleFixtureTestCase
{
    /** @var array<class-string, array<string, list<Diagnostic>>>|null */
    private static ?array $diagnostics = null;

    protected static function configFile(): string
    {
        return 'split.neon';
    }

    public function testACheckThatOnlyTheSplitByLengthDecidesIsProven(): void
    {
        self::assertSame([], $this->outcome(SplitProvenFixture::class, 'leavesLessThanOneUnitPerLine'));
    }

    public function testACounterexampleFoundByOneCaseIsReportedAndReplaysInPhp(): void
    {
        $found = $this->outcome(SplitRefutedFixture::class, 'sumIsExact');

        self::assertCount(1, $found);
        self::assertSame('contracts.ensuresViolated', $found[0]->identifier);
        self::assertStringStartsWith('Postcondition #[Ensures(\'array_sum($result) === $totalDiscount\')] can be violated. Counterexample: $totalDiscount = ', $found[0]->message);
        self::assertDoesNotMatchRegularExpression('/a list of \d+ elements starting with/', $found[0]->message);

        $matched = preg_match('/\$totalDiscount = (\d+), \$lineTotals = \[([\d, ]*)\] => \$result = \[([\d, ]*)\] \(return at line \d+\)\.$/', $found[0]->message, $parts);
        self::assertSame(1, $matched, $found[0]->message);
        $lineTotals = array_map(intval(...), array_filter(explode(', ', $parts[2]), static fn (string $item): bool => $item !== ''));
        $shown = array_map(intval(...), array_filter(explode(', ', $parts[3]), static fn (string $item): bool => $item !== ''));

        // The reported inputs are what the method is called with, whatever its declared types say.
        $result = (new ReflectionMethod(SplitRefutedFixture::class, 'sumIsExact'))->invoke(null, (int) $parts[1], $lineTotals);
        self::assertIsArray($result);
        self::assertSame($shown, $result, 'PHP returns what the message says');
        self::assertNotSame((int) $parts[1], array_sum($result), 'the postcondition is false in PHP for the reported inputs');
    }

    /**
     * @param class-string $class
     * @return list<Diagnostic>
     */
    private function outcome(string $class, string $method): array
    {
        self::$diagnostics ??= $this->diagnose([SplitProvenFixture::class, SplitRefutedFixture::class]);

        return self::$diagnostics[$class][$method] ?? [];
    }
}
