<?php
declare(strict_types=1);
namespace PhpstanContracts\Tests\Rule;
use PhpstanContracts\Tests\Fixtures\ForeachJumpsFixture;
use PhpstanContracts\Tests\Support\RuleFixtureTestCase;
final class ForeachJumpsRuleTest extends RuleFixtureTestCase
{
    public function testJumpsThrowsAndNestedLoopsAreProvenOrRefuted(): void
    {
        [$outer, $inner] = $this->foreachLinesOf('overflowsInTheInnerLoop');
        $this->assertOutcomes([
            ForeachJumpsFixture::class => [
                'stopsAtTheFirstLargeAmount' => [],
                'stopsBeforeTheFirstLargeAmount' => ['contracts.ensuresViolated: Postcondition #[Ensures(\'implies(count($amounts) > 0 && $amounts[0] > 500, $result === $amounts[0])\')] can be violated. Counterexample: $amounts = ['],
                'skipsTheSmallAmounts' => [],
                'countsWhatItDoesNotSkip' => ['contracts.ensuresViolated: Postcondition #[Ensures(\'$result === count($amounts)\')] can be violated. Counterexample: $amounts = ['],
                'sumsThePositiveAmountsUntilAZero' => [],
                'keepsTheKeyWhereItStopped' => [],
                'indexOfTheFirstAmountAbove' => [],
                'returnsTheWrongElement' => ['contracts.ensuresViolated: Postcondition #[Ensures(\'implies($result >= 0, $amounts[$result] > $limit)\')] can be violated. Counterexample: $amounts = ['],
                'throwsAtTheFirstZero' => [],
                'throwsOnlyFromTheSecondElement' => ['contracts.throwsViolated: #[Throws(InvalidArgumentException::class, when: \'in_array(0, $quantities, true)\')] is violated: the method can return although the condition holds. Counterexample: $quantities = ['],
                'throwsWithoutDeclaringIt' => ['contracts.unexpectedThrow: `throw new \\InvalidArgumentException(\'A quantity is zero.\')` can be reached where no #[Throws] allows it, in iteration 2 (key 1) of the foreach at line '],
                'addsEveryPair' => [],
                'countsTheRowsWithoutALargeColumn' => [],
                'takesTheFirstMatchingPair' => [],
                'breaksOnlyTheInnerLoop' => ['contracts.ensuresViolated: Postcondition #[Ensures(\'implies(count($rows) > 0 && $result > 0, $result === $rows[0])\')] can be violated. Counterexample: $rows = ['],
                'overflowsInTheInnerLoop' => ['contracts.overflow: Possible integer overflow: `$total + $column` can leave the PHP int range, in iteration 2 (key 1) of the foreach at line ' . $inner . ', in iteration 1 (key 0) of the foreach at line ' . $outer . '. Counterexample: '],
                'nestsPastTheBudget' => ['contracts.unsupported: These nested loops would be unrolled 20 × 20 = 400 times, more than contracts.maxLoopUnroll (100). Bound the lists more tightly with #[Requires], extract the inner loop into a smaller method of this file, or raise contracts.maxLoopUnroll.'],
                'callsAHelperThatLoopsInsideALoop' => [],
                'callsAHelperThatLoops' => [],
                'callsAHelperWithAnUnboundedList' => ['contracts.requiresViolated: The call to PhpstanContracts\\Tests\\Fixtures\\ForeachJumpsFixture::sumOf() can violate its precondition #[Requires(\'count($amounts) <= 5\')]. Counterexample: $amounts = ['],
                'sumOf' => [],
            ],
        ]);
    }

    /**
     * PHP refuses to compile these jumps, so they cannot sit in a fixture class that is loaded:
     * the rule reads the source, and says what is wrong rather than guessing.
     */
    public function testJumpsPhpWouldNotCompileAreRejectedWithAReason(): void
    {
        $code = <<<'PHP'
        <?php
        declare(strict_types=1);
        namespace PhpstanContracts\Tests\Generated;
        use PhpstanContracts\Attribute\Pure;
        final class InvalidJumps
        {
            /** @param list<int> $xs */
            #[Pure]
            public static function breakOutsideALoop(array $xs): int
            {
                break;
            }
            /** @param list<int<0, 10>> $xs */
            #[Pure]
            public static function breakTooManyLevels(array $xs): int
            {
                foreach ($xs as $x) {
                    break 3;
                }
                return 0;
            }
            /** @param list<int<0, 10>> $xs */
            #[Pure]
            public static function continueTooManyLevels(array $xs): int
            {
                foreach ($xs as $x) {
                    continue 2;
                }
                return 0;
            }
            /** @param list<int<0, 10>> $xs */
            #[Pure]
            public static function breakWithAVariableLevel(array $xs, int $n): int
            {
                foreach ($xs as $x) {
                    break $n;
                }
                return 0;
            }
            /** @param list<int<0, 10>> $xs */
            #[Pure]
            public static function breakWithLevelZero(array $xs): int
            {
                foreach ($xs as $x) {
                    break 0;
                }
                return 0;
            }
        }
        PHP;
        $file = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'contracts-invalid-jumps-' . bin2hex(random_bytes(4)) . '.php';
        file_put_contents($file, $code);

        try {
            $errors = $this->gatherAnalyserErrors([$file]);
        } finally {
            unlink($file);
        }

        $messages = [];
        foreach ($errors as $error) {
            if (str_starts_with((string) $error->getIdentifier(), 'contracts.')) {
                $messages[] = $error->getIdentifier() . ': ' . $error->getMessage();
            }
        }
        sort($messages);
        self::assertSame([
            'contracts.unsupported: break 0 is not valid: the level of break starts at 1.',
            'contracts.unsupported: break 3 leaves more loops than enclose it here (1).',
            'contracts.unsupported: break is only supported inside a foreach.',
            'contracts.unsupported: break needs a literal level, such as break 2.',
            'contracts.unsupported: continue 2 leaves more loops than enclose it here (1).',
        ], $messages);
    }

    /**
     * The lines of the first two foreach statements of a method of the fixture.
     *
     * @return array{int, int}
     */
    private function foreachLinesOf(string $method): array
    {
        $lines = file((new \ReflectionClass(ForeachJumpsFixture::class))->getFileName() ?: '', FILE_IGNORE_NEW_LINES);
        self::assertIsArray($lines);
        $found = [];
        $inside = false;
        foreach ($lines as $index => $line) {
            if (str_contains($line, 'function ' . $method . '(')) {
                $inside = true;
            }
            if ($inside && str_contains($line, 'foreach (')) {
                $found[] = $index + 1;
            }
        }
        self::assertGreaterThanOrEqual(2, count($found));

        return [$found[0], $found[1]];
    }
}
