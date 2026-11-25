<?php

declare(strict_types=1);

namespace PhpstanContracts\Tests\Unit\Report;

use PhpstanContracts\Report\UnknownExplainer;
use PhpstanContracts\Translate\Encoding;
use PhpstanContracts\Translate\LoopBound;
use PHPUnit\Framework\TestCase;

final class UnknownExplainerTest extends TestCase
{
    private UnknownExplainer $explainer;

    protected function setUp(): void
    {
        $this->explainer = new UnknownExplainer();
    }

    public function testACheckWithoutALoopOrANonLinearOperationHasNothingToExplain(): void
    {
        self::assertSame('', $this->explainer->explain(new Encoding()));
        self::assertSame('', $this->explainer->explain(new Encoding(), [4, 5]), 'there is no list to split without a loop');
    }

    public function testALoopNamesItsBoundAndTheWaysToShrinkTheProof(): void
    {
        $encoding = new Encoding();
        $encoding->noteUnrolledLoop(25, new LoopBound(5, '$lineTotals', null, true));

        self::assertSame(
            'The proof unrolls the foreach at line 25 up to 5 iterations. Try a smaller bound with #[Requires(\'count($lineTotals) <= 3\')], a longer contracts.timeoutMs, or a #[Requires] that narrows the ranges.',
            $this->explainer->explain($encoding),
        );
    }

    public function testALoopAndNonLinearOperationsAreNamedTogether(): void
    {
        $encoding = new Encoding();
        $encoding->noteUnrolledLoop(25, new LoopBound(5, '$lineTotals', null, true));
        $encoding->noteNonLinear('product', '$totalDiscount * $lineTotal');
        $encoding->noteNonLinear('division', 'intdiv($totalDiscount * $lineTotal, $subtotal)');

        self::assertSame(
            'The proof unrolls the foreach at line 25 up to 5 iterations, and the code has a product of two variables (`$totalDiscount * $lineTotal`) and a division by a variable (`intdiv($totalDiscount * $lineTotal, $subtotal)`). Try a smaller bound with #[Requires(\'count($lineTotals) <= 3\')], a longer contracts.timeoutMs, or a #[Requires] that narrows the ranges.',
            $this->explainer->explain($encoding),
        );
    }

    public function testNonLinearArithmeticWithoutALoopDoesNotSuggestABound(): void
    {
        $encoding = new Encoding();
        $encoding->noteNonLinear('modulo', '$result % $perPage');

        $message = $this->explainer->explain($encoding);

        self::assertSame(
            'The code has a modulo by a variable (`$result % $perPage`), and Z3 decides non-linear arithmetic only in part. Try a longer contracts.timeoutMs or a #[Requires] that narrows the ranges.',
            $message,
        );
    }

    public function testTheUndecidedLengthsOfASplitAreNamed(): void
    {
        $encoding = new Encoding();
        $encoding->noteUnrolledLoop(25, new LoopBound(8, '$lineTotals', null, true));
        $encoding->noteNonLinear('product', '$a * $b');

        $message = $this->explainer->explain($encoding, [6, 7, 8]);

        self::assertStringContainsString('The split of the proof by the length of $lineTotals did not decide the lengths 6, 7, and 8.', $message);
        self::assertStringContainsString('#[Requires(\'count($lineTotals) <= 3\')]', $message);
        self::assertStringNotContainsString('did not decide', $this->explainer->explain($encoding));
    }

    public function testSeveralLoopsAreListedByLineAndAnInnerLoopIsCountedOnce(): void
    {
        $encoding = new Encoding();
        $encoding->noteUnrolledLoop(40, new LoopBound(3, '$parts', null, false));
        $encoding->noteUnrolledLoop(30, new LoopBound(2, '$row', null, true));
        $encoding->noteUnrolledLoop(30, new LoopBound(2, '$row', null, true));

        $message = $this->explainer->explain($encoding);

        self::assertStringStartsWith('The proof unrolls the foreach at line 30 up to 2 iterations and the foreach at line 40 up to 3 iterations.', $message);
        self::assertStringContainsString('a smaller bound on the list it is built from with #[Requires]', $message, 'the widest loop cannot be named in a contract');
    }

    public function testALoopOfOneIterationHasNoSmallerBoundToSuggest(): void
    {
        $encoding = new Encoding();
        $encoding->noteUnrolledLoop(9, new LoopBound(1, '$rows', 'count($rows) <= 1', true));

        self::assertStringNotContainsString('a smaller bound', $this->explainer->explain($encoding));
    }
}
