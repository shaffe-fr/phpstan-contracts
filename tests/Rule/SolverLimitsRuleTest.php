<?php

declare(strict_types=1);

namespace PhpstanContracts\Tests\Rule;

use PhpstanContracts\Examples\RealWorld\SolverLimits;
use PhpstanContracts\Tests\Fixtures\LimitsFixture;
use PhpstanContracts\Tests\Support\RuleFixtureTestCase;

/**
 * A question that Z3 cannot settle in time must come out as `contracts.unknown`, never
 * as a proof and never as a refutation. The timeout is short on purpose.
 */
final class SolverLimitsRuleTest extends RuleFixtureTestCase
{
    protected static function configFile(): string
    {
        return 'short-timeout.neon';
    }

    public function testAQuestionThatIsNotDecidedInTimeIsNotAPass(): void
    {
        $this->assertOutcomes([
            LimitsFixture::class => [
                'easyQuestion' => [],
                'nonLinearDivisibility' => ['contracts.unknown: Not proven: Z3 answered "unknown" after 2000 ms'],
            ],
            SolverLimits::class => [
                'offsetDivisibility' => ['contracts.unknown'],
                'scaled' => ['contracts.unknown'],
            ],
        ]);
    }
}
