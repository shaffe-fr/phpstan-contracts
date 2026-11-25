<?php

declare(strict_types=1);

namespace PhpstanContracts\Tests\Rule;

use PhpstanContracts\Tests\Fixtures\LimitsFixture;
use PhpstanContracts\Tests\Support\RuleFixtureTestCase;

/**
 * A check that stays undecided says what makes it expensive when the encoder knows: the products
 * and divisions that are not linear. The timeout is short on purpose, and the fixtures are far
 * beyond what Z3 decides in it. The messages of the methods with a loop are in
 * {@see UnknownSplitMessageRuleTest}.
 */
final class UnknownMessageRuleTest extends RuleFixtureTestCase
{
    protected static function configFile(): string
    {
        return 'short-timeout.neon';
    }

    public function testNonLinearArithmeticWithoutALoopDoesNotMentionABound(): void
    {
        $messages = $this->unknownMessages(LimitsFixture::class, 'nonLinearDivisibility');

        self::assertCount(1, $messages);
        self::assertStringStartsWith('Not proven: Z3 answered "unknown" after 2000 ms for #[Ensures(\'$result % $perPage === 0\')]. ', $messages[0]);
        self::assertStringContainsString('a product of two variables (`($page - 1) * $perPage`) and a modulo by a variable (`$result % $perPage`)', $messages[0]);
        self::assertStringContainsString('Try a longer contracts.timeoutMs or a #[Requires] that narrows the ranges.', $messages[0]);
        self::assertStringNotContainsString('unrolls', $messages[0]);
        self::assertStringNotContainsString('smaller bound', $messages[0]);
    }

    public function testTheSatisfiabilityOfThePreconditionsKeepsItsMessage(): void
    {
        $messages = $this->unknownMessages(LimitsFixture::class, 'preconditionsThatMayBeContradictory');

        self::assertSame(['Not proven: Z3 answered "unknown" after 2000 ms for the satisfiability of the parameter types and #[Requires], so every proof of this method may be vacuous.'], $messages);
    }

    /**
     * @param class-string $class
     * @return list<string>
     */
    private function unknownMessages(string $class, string $method): array
    {
        $messages = [];
        foreach ($this->diagnose([$class])[$class][$method] ?? [] as $diagnostic) {
            self::assertSame('contracts.unknown', $diagnostic->identifier);
            $messages[] = $diagnostic->message;
        }

        return $messages;
    }
}
