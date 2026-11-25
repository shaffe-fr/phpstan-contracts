<?php

declare(strict_types=1);

namespace PhpstanContracts\Tests\Rule;

use PhpstanContracts\Tests\Fixtures\RetryWithoutPushFixture;
use PhpstanContracts\Tests\Support\RuleFixtureTestCase;

/**
 * The main script of a method with a loop leaves a check `unknown`; asked again alone, in a script
 * with no `push`, Z3 decides it. With the scope the retry stayed `unknown` however long it ran.
 * The time limit is far above what the decided retry needs, so a slower runner still decides it.
 */
final class RetryWithoutPushRuleTest extends RuleFixtureTestCase
{
    protected static function configFile(): string
    {
        return 'retry.neon';
    }

    public function testACheckThatOnlyTheRetryWithoutAScopeDecidesIsProven(): void
    {
        $this->assertOutcomes([
            RetryWithoutPushFixture::class => [
                'leavesLessThanOneUnitPerLine' => [],
            ],
        ]);
    }
}
