<?php

declare(strict_types=1);

namespace PhpstanContracts\Tests\Rule;

use PhpstanContracts\Tests\Fixtures\RunBudgetFixture;
use PhpstanContracts\Tests\Support\RuleFixtureTestCase;

/**
 * Once the solver time of the run is spent, a method that would need Z3 is not tried, and it gets one
 * `contracts.unknown` for the whole method, whatever its number of checks. The budget is shared by the
 * tests of this class, so it has a single test.
 */
final class RunBudgetRuleTest extends RuleFixtureTestCase
{
    protected static function configFile(): string
    {
        return 'run-budget.neon';
    }

    public function testTheMethodsAfterTheBudgetAreNotTriedAndGetOneDiagnosticEach(): void
    {
        $this->assertOutcomes([
            RunBudgetFixture::class => [
                'first' => [],
                'second' => ['contracts.unknown: Not verified: the solver budget of this run is exhausted (contracts.runBudgetMs is 1 ms), so Z3 was not asked about this method.'],
                'third' => ['contracts.unknown: Not verified: the solver budget of this run is exhausted (contracts.runBudgetMs is 1 ms), so Z3 was not asked about this method. Raise contracts.runBudgetMs'],
            ],
        ]);
    }
}
