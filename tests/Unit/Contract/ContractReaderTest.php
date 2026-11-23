<?php

declare(strict_types=1);

namespace PhpstanContracts\Tests\Unit\Contract;

use PhpParser\Node\Stmt\ClassMethod;
use PhpParser\NodeFinder;
use PhpParser\ParserFactory;
use PhpstanContracts\Contract\ContractReader;
use PhpstanContracts\Contract\MethodContract;
use PhpstanContracts\Exception\VerificationRejected;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class ContractReaderTest extends TestCase
{
    private ContractReader $reader;

    protected function setUp(): void
    {
        $this->reader = new ContractReader();
    }

    public function testRequiresEnsuresAndPureAreRead(): void
    {
        $contract = $this->read(<<<'PHP'
            #[\PhpstanContracts\Attribute\Requires('$a > 0')]
            #[\PhpstanContracts\Attribute\Requires('$b > 0')]
            #[\PhpstanContracts\Attribute\Ensures('$result >= 0')]
            #[\PhpstanContracts\Attribute\Pure]
            PHP);

        self::assertCount(2, $contract->requires);
        self::assertCount(1, $contract->ensures);
        self::assertTrue($contract->pure);
        self::assertFalse($contract->isEmpty());
        self::assertSame('$a > 0', $contract->requires[0]->source);
        self::assertSame('$b > 0', $contract->requires[1]->source);
        self::assertSame('$result >= 0', $contract->ensures[0]->source);
    }

    public function testThrowsIsReadWithItsClassAndOptionalCondition(): void
    {
        $contract = $this->read(<<<'PHP'
            #[\PhpstanContracts\Attribute\Throws(\InvalidArgumentException::class, when: '$a < 0')]
            #[\PhpstanContracts\Attribute\Throws(class: \LogicException::class)]
            PHP);

        self::assertFalse($contract->isEmpty());
        self::assertCount(2, $contract->throws);
        self::assertSame('InvalidArgumentException', $contract->throws[0]->class);
        self::assertSame('$a < 0', $contract->throws[0]->when?->source);
        self::assertSame('LogicException', $contract->throws[1]->class);
        self::assertNull($contract->throws[1]->when);
    }

    public function testEachClauseKeepsTheLineOfItsAttribute(): void
    {
        $contract = $this->read(<<<'PHP'
            #[\PhpstanContracts\Attribute\Requires('$a > 0')]

            #[\PhpstanContracts\Attribute\Ensures('$result >= 0')]
            PHP);

        self::assertSame($contract->requires[0]->line + 2, $contract->ensures[0]->line);
    }

    public function testTheContractIsAParsedExpression(): void
    {
        $contract = $this->read("#[\\PhpstanContracts\\Attribute\\Ensures('\$result === \$a + 1')]");

        self::assertInstanceOf(\PhpParser\Node\Expr\BinaryOp\Identical::class, $contract->ensures[0]->expression);
    }

    public function testOtherAttributesAreIgnored(): void
    {
        $contract = $this->read('#[\\Deprecated] #[\\SomeVendor\\Requires(\'$a > 0\')]');

        self::assertTrue($contract->isEmpty());
        self::assertSame([], $contract->requires);
    }

    public function testAMethodWithoutAttributesHasAnEmptyContract(): void
    {
        self::assertTrue($this->read('')->isEmpty());
    }

    public function testPureAloneIsNotEmpty(): void
    {
        self::assertFalse($this->read('#[\\PhpstanContracts\\Attribute\\Pure]')->isEmpty());
    }

    /**
     * @return iterable<string, array{string, string}>
     */
    public static function invalidContracts(): iterable
    {
        yield 'no argument' => ['#[\\PhpstanContracts\\Attribute\\Ensures]', 'plain string literal'];
        yield 'an integer instead of a string' => ['#[\\PhpstanContracts\\Attribute\\Ensures(5)]', 'plain string literal'];
        yield 'a concatenation' => ['#[\\PhpstanContracts\\Attribute\\Ensures(\'$a\' . \'> 0\')]', 'plain string literal'];
        yield 'a syntax error' => ['#[\\PhpstanContracts\\Attribute\\Ensures(\'$result >=\')]', 'is not valid PHP'];
        yield 'two statements' => ['#[\\PhpstanContracts\\Attribute\\Ensures(\'$a; $b\')]', 'exactly one PHP expression'];
        yield 'a statement that is not an expression' => ['#[\\PhpstanContracts\\Attribute\\Ensures(\'if ($a) {}\')]', 'exactly one PHP expression'];
        yield 'a class given as a string' => ['#[\\PhpstanContracts\\Attribute\\Throws(\'LogicException\')]', 'Name::class'];
        yield 'a condition that is not valid PHP' => ['#[\\PhpstanContracts\\Attribute\\Throws(\\LogicException::class, when: \'$a <\')]', 'is not valid PHP'];
    }

    #[DataProvider('invalidContracts')]
    public function testInvalidContractsAreRejectedWithTheirLine(string $attribute, string $reason): void
    {
        try {
            $this->read($attribute);
            self::fail('The contract should have been rejected.');
        } catch (VerificationRejected $rejected) {
            self::assertSame('contracts.invalidContract', $rejected->identifier);
            self::assertStringContainsString($reason, $rejected->getMessage());
            self::assertSame(4, $rejected->errorLine, 'the attribute sits on line 4 of the wrapper');
        }
    }

    private function read(string $attributes): MethodContract
    {
        $code = "<?php\nfinal class Wrapper\n{\n    {$attributes}\n    public function method(int \$a, int \$b): int { return \$a; }\n}\n";
        $statements = (new ParserFactory())->createForNewestSupportedVersion()->parse($code) ?? [];
        $method = (new NodeFinder())->findFirstInstanceOf($statements, ClassMethod::class);
        self::assertInstanceOf(ClassMethod::class, $method);

        return $this->reader->read($method);
    }

    /**
     * Class names are case-insensitive, so PHP instantiates this attribute as Ensures.
     */
    public function testAttributeNamesAreReadWhateverTheirCase(): void
    {
        $contract = $this->read("#[\\PhpstanContracts\\Attribute\\ENSURES('\$result === 6')] #[\\phpstancontracts\\attribute\\pure]");

        self::assertCount(1, $contract->ensures);
        self::assertTrue($contract->pure);
    }
}
