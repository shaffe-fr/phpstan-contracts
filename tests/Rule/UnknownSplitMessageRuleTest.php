<?php

declare(strict_types=1);

namespace PhpstanContracts\Tests\Rule;

use PhpstanContracts\Tests\Fixtures\UnknownBeyondSplitFixture;
use PhpstanContracts\Tests\Fixtures\UnknownCostFixture;
use PhpstanContracts\Tests\Support\Diagnostic;
use PhpstanContracts\Tests\Support\RuleFixtureTestCase;

/**
 * The message of a check that stays undecided in a method with a loop: what the proof unrolls, and
 * the lengths that the split of the proof by the length of the list did not decide. The timeout is
 * short on purpose and the fixtures are far beyond what Z3 decides in it, with the split too.
 * Only the message of the postcondition is read: at this timeout a runner may leave other checks of
 * the method undecided too.
 */
final class UnknownSplitMessageRuleTest extends RuleFixtureTestCase
{
    /** @var array<class-string, array<string, list<Diagnostic>>> */
    private static array $diagnostics = [];

    protected static function configFile(): string
    {
        return 'unknown-split.neon';
    }

    public function testALoopWithNonLinearArithmeticNamesBothAndTheWaysOut(): void
    {
        $message = $this->postconditionMessage(UnknownCostFixture::class);

        self::assertStringStartsWith('Not proven: Z3 answered "unknown" after 1000 ms for #[Ensures(\'array_sum($result) === $totalDiscount\')]. ', $message);
        self::assertMatchesRegularExpression('/The proof unrolls the foreach at line \d+ up to 8 iterations and the foreach at line \d+ up to 8 iterations, and the code has /', $message);
        self::assertStringContainsString('a product of two variables (`$totalDiscount * $lineTotal`)', $message);
        self::assertStringContainsString('a division by a variable (`intdiv($totalDiscount * $lineTotal, $subtotal)`)', $message);
        self::assertStringContainsString('Try a smaller bound with #[Requires(\'count($lineTotals) <= 3\')], a longer contracts.timeoutMs, or a #[Requires] that narrows the ranges.', $message);
    }

    public function testTheLengthsThatTheSplitByLengthDidNotDecideAreListed(): void
    {
        $message = $this->postconditionMessage(UnknownCostFixture::class);

        // Which of the shorter lengths a runner decides in the time limit varies, the longest never.
        self::assertMatchesRegularExpression('/The split of the proof by the length of \$lineTotals did not decide the lengths? [\d, and]*\b8\. Try /', $message);
    }

    public function testAProofWithTooManyLengthsIsNotSplitAndSaysNothingOfLengths(): void
    {
        $message = $this->postconditionMessage(UnknownBeyondSplitFixture::class);

        self::assertStringContainsString('up to 11 iterations', $message);
        self::assertStringNotContainsString('did not decide', $message, 'a split of 13 cases is not tried');
    }

    /**
     * @param class-string $class
     */
    private function postconditionMessage(string $class): string
    {
        self::$diagnostics[$class] ??= $this->diagnose([$class])[$class];

        $messages = [];
        foreach (self::$diagnostics[$class]['proportionalSplit'] ?? [] as $diagnostic) {
            self::assertSame('contracts.unknown', $diagnostic->identifier);
            if (str_contains($diagnostic->message, '#[Ensures(')) {
                $messages[] = $diagnostic->message;
            }
        }
        self::assertCount(1, $messages);

        return $messages[0];
    }
}
