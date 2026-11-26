<?php

declare(strict_types=1);

namespace PhpstanContracts\Tests\Rule;

use PhpstanContracts\Tests\Fixtures\CrossFile\Callees;
use PhpstanContracts\Tests\Fixtures\CrossFile\CrossFileInteractions;
use PhpstanContracts\Tests\Support\RuleFixtureTestCase;

/**
 * The body of a method of another file is part of the cost of the verified method that calls it:
 * its unrolled loops and divisions count in the prediction, and its checks in the method budget,
 * which is 1 ms here so that Z3 never answers.
 */
final class CrossFileCostRuleTest extends RuleFixtureTestCase
{
    protected static function configFile(): string
    {
        return 'cost-report.neon';
    }

    public function testTheLoopAndTheDivisionsOfACalleeOfAnotherFileAreCounted(): void
    {
        $diagnostics = $this->diagnose([CrossFileInteractions::class, Callees::class])[CrossFileInteractions::class]['costlySplitInACallee'] ?? [];
        $costly = array_values(array_filter($diagnostics, static fn ($diagnostic): bool => $diagnostic->identifier === 'contracts.costly'));
        self::assertCount(1, $costly);
        self::assertStringContainsString('up to 8 iterations', $costly[0]->message);
        self::assertStringContainsString('8 divisions or modulos by a variable', $costly[0]->message);
    }

    public function testTheChecksOfACalleeOfAnotherFileAreCutByTheMethodBudget(): void
    {
        $diagnostics = $this->diagnose([CrossFileInteractions::class, Callees::class])[CrossFileInteractions::class]['nonLinearCallee'] ?? [];
        $unknown = array_values(array_filter($diagnostics, static fn ($diagnostic): bool => $diagnostic->identifier === 'contracts.unknown'));
        self::assertNotSame([], $unknown);
        $messages = implode("\n", array_map(static fn ($diagnostic): string => $diagnostic->message, $unknown));
        self::assertStringContainsString('the time budget of this method (contracts.methodBudgetMs, 1 ms) ran out', $messages);
        self::assertStringContainsString('#[Ensures(\'$result % $perPage === 0\')]', $messages);
    }
}
