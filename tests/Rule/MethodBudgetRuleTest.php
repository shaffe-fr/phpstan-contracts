<?php

declare(strict_types=1);

namespace PhpstanContracts\Tests\Rule;

use PhpstanContracts\Tests\Fixtures\CostFixture;
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

    public function testTheMessageSaysHowManyChecksWereNotTriedAndThePredictedWorstCaseWithoutAnyMeasuredTime(): void
    {
        $messages = array_map(
            static fn ($diagnostic): string => $diagnostic->message,
            $this->diagnose([LimitsFixture::class])[LimitsFixture::class]['nonLinearDivisibility'] ?? [],
        );
        // The satisfiability of the preconditions is about the whole method, not one of its checks.
        $messages = array_values(array_filter($messages, static fn (string $message): bool => !str_contains($message, 'the satisfiability of the parameter types')));

        self::assertNotSame([], $messages);
        foreach ($messages as $message) {
            self::assertMatchesRegularExpression('/ \d+ of the \d+ checks of this method (was|were) not tried\. /', $message);
            self::assertStringContainsString('Predicted worst case for this method: 1 ms (cut by contracts.methodBudgetMs), from its structure', $message);
            self::assertStringContainsString('Other ways out: a larger contracts.methodBudgetMs or simpler arithmetic (fewer products or divisions of variables).', $message);
        }
    }

    public function testCostWarningsAreOffByDefault(): void
    {
        foreach ($this->diagnose([CostFixture::class])[CostFixture::class] as $diagnostics) {
            foreach ($diagnostics as $diagnostic) {
                self::assertNotSame('contracts.costly', $diagnostic->identifier);
            }
        }
    }
}

