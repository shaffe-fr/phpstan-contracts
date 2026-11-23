<?php

declare(strict_types=1);

namespace PhpstanContracts\Tests\Rule;

use PhpstanContracts\Rule\PropertyHookContractRule;
use PHPStan\Rules\Rule;
use PHPStan\Testing\RuleTestCase;

/**
 * The fixture uses PHP 8.4 syntax: PHPStan parses it, the test never loads it.
 *
 * @extends RuleTestCase<PropertyHookContractRule>
 */
final class PropertyHookRuleTest extends RuleTestCase
{
    /**
     * @return list<string>
     */
    public static function getAdditionalConfigFiles(): array
    {
        // Property hooks exist from PHP 8.4 on, whatever PHP runs the test.
        return [__DIR__ . '/../../extension.neon', __DIR__ . '/../config/default.neon', __DIR__ . '/../config/php84.neon'];
    }

    protected function getRule(): Rule
    {
        return self::getContainer()->getByType(PropertyHookContractRule::class);
    }

    public function testAContractOnAPropertyHookIsRejected(): void
    {
        $message = 'Contracts on property hooks are not verified: move the logic to a method and put the contract there.';

        $this->analyse([__DIR__ . '/../Fixtures/HookContracts.php'], [
            [$message, 16],
            [$message, 21],
        ]);
    }
}
