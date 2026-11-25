<?php

declare(strict_types=1);

namespace PhpstanContracts\Tests\Rule;

use PhpstanContracts\Tests\Fixtures\LimitsFixture;
use PhpstanContracts\Tests\Support\RuleFixtureTestCase;

/**
 * A method whose budget is spent before Z3 can answer is `contracts.unknown` for that reason: not a
 * proof, not a counterexample and not a solver failure.
 */
final class MethodBudgetRuleTest extends RuleFixtureTestCase
{
    protected static function configFile(): string
    {
        return 'method-budget.neon';
    }

    public function testACheckThatTheBudgetCutIsUnknownAndSaysSo(): void
    {
        $diagnostics = $this->diagnose([LimitsFixture::class])[LimitsFixture::class]['nonLinearDivisibility'] ?? [];

        self::assertNotSame([], $diagnostics);
        foreach ($diagnostics as $diagnostic) {
            self::assertSame('contracts.unknown', $diagnostic->identifier);
            self::assertStringStartsWith('Not proven: the time budget of this method (contracts.methodBudgetMs, 1 ms) ran out before Z3 decided ', $diagnostic->message);
            self::assertStringContainsString('Raise contracts.methodBudgetMs', $diagnostic->message);
        }
        $messages = implode("\n", array_map(static fn ($diagnostic): string => $diagnostic->message, $diagnostics));
        self::assertStringContainsString('#[Ensures(\'$result % $perPage === 0\')]', $messages);
    }
}
