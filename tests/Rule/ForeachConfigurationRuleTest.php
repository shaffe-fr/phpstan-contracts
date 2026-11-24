<?php

declare(strict_types=1);

namespace PhpstanContracts\Tests\Rule;

use PhpstanContracts\Tests\Fixtures\ForeachConfigurationFixture;
use PhpstanContracts\Tests\Support\RuleFixtureTestCase;

/**
 * `contracts.loopUnroll` and `contracts.maxLoopUnroll` change what is unrolled and what is refused.
 */
final class ForeachConfigurationRuleTest extends RuleFixtureTestCase
{
    protected static function configFile(): string
    {
        return 'loop-unroll.neon';
    }

    public function testTheUnrollingAndTheBudgetFollowTheConfiguration(): void
    {
        $this->assertOutcomes([
            ForeachConfigurationFixture::class => [
                'fitsTheDefault' => [],
                'exceedsTheDefault' => ['contracts.loopBound: The proof unrolls this foreach up to 3 iterations, and $amounts can have more. This is a limit of the proof, not a bug in the code. If the real maximum is known, add #[Requires(\'count($amounts) <= 50\')]; otherwise raise contracts.loopUnroll. Counterexample: $amounts = ['],
                'fitsTheBudget' => [],
                'exceedsTheBudget' => ['contracts.unsupported: This foreach would be unrolled 9 times, more than contracts.maxLoopUnroll (8). Bound the list more tightly with #[Requires], or raise contracts.maxLoopUnroll.'],
            ],
        ]);
    }
}
