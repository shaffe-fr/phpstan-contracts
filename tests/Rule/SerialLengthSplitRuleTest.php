<?php

declare(strict_types=1);

namespace PhpstanContracts\Tests\Rule;

use PhpstanContracts\Tests\Fixtures\SplitProvenFixture;
use PhpstanContracts\Tests\Support\RuleFixtureTestCase;

/**
 * With `parallelism: 1` the cases of the split run one after another, and the answer is the same.
 */
final class SerialLengthSplitRuleTest extends RuleFixtureTestCase
{
    protected static function configFile(): string
    {
        return 'split-serial.neon';
    }

    public function testTheCasesRunOneAfterAnotherAndTheCheckIsProven(): void
    {
        $this->assertOutcomes([
            SplitProvenFixture::class => [
                'leavesLessThanOneUnitPerLine' => [],
            ],
        ]);
    }
}
