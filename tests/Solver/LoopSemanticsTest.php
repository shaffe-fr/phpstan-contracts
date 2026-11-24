<?php
declare(strict_types=1);
namespace PhpstanContracts\Tests\Solver;
use PhpstanContracts\Attribute\Ensures;
use PhpstanContracts\Attribute\Requires;
use PhpstanContracts\Tests\Fixtures\ForeachSemantics;
use PhpstanContracts\Tests\Support\PhpContract;
use PHPUnit\Framework\TestCase;
use ReflectionClass;
use ReflectionMethod;
use RuntimeException;
/**
 * The loops of `ForeachSemantics` are proven by ForeachRuleTest. Here PHP runs the same methods
 * on every list the proof covers, and evaluates their `#[Ensures]` on what it returns: the
 * contracts pin the result exactly, so a translation that reads a loop differently from PHP
 * is either refuted there or contradicted here.
 */
final class LoopSemanticsTest extends TestCase
{
    /** Values that tell the branches of the fixtures apart: zero, the edges of 50, the edges of the range. */
    private const EDGES = [0, 1, 50, 51, 99, 100];
    public function testPhpAgreesWithTheContractsOfEveryLoopForEveryListTheProofCovers(): void
    {
        $evaluated = 0;
        foreach ((new ReflectionClass(ForeachSemantics::class))->getMethods(ReflectionMethod::IS_PUBLIC) as $method) {
            $contracts = array_map(
                static fn ($attribute): string => $attribute->newInstance()->expression,
                $method->getAttributes(Ensures::class),
            );
            self::assertNotSame([], $contracts, $method->getName() . ' has no #[Ensures].');
            $requires = array_map(
                static fn ($attribute): string => $attribute->newInstance()->expression,
                $method->getAttributes(Requires::class),
            );
            $listsTried = 0;
            foreach ($this->lists() as $items) {
                if (!$this->holdsAll($requires, $method, [$items], null)) {
                    continue;
                }
                $result = $method->invoke(null, $items);
                foreach ($contracts as $contract) {
                    self::assertTrue(
                        $this->evaluate($contract, $method, [$items], $result),
                        sprintf('%s: PHP returns %s for %s, which falsifies #[Ensures(\'%s\')].', $method->getName(), var_export($result, true), json_encode($items), $contract),
                    );
                    ++$evaluated;
                }
                ++$listsTried;
            }
            self::assertGreaterThan(100, $listsTried, $method->getName());
        }
        self::assertGreaterThan(1000, $evaluated);
    }
    /**
     * Every list of up to 3 elements over the edge values, the empty list included, then seeded
     * random lists of up to 5 elements, which the bound of the `#[Requires]` partly rejects.
     *
     * @return iterable<list<int>>
     */
    private function lists(): iterable
    {
        $level = [[]];
        for ($size = 0; $size <= 3; ++$size) {
            $next = [];
            foreach ($level as $list) {
                yield $list;
                foreach (self::EDGES as $edge) {
                    $next[] = [...$list, $edge];
                }
            }
            $level = $next;
        }
        mt_srand(20240611);
        for ($i = 0; $i < 300; ++$i) {
            $list = [];
            for ($size = mt_rand(0, 5); $size > 0; --$size) {
                $list[] = mt_rand(0, 100);
            }
            yield $list;
        }
    }
    /**
     * @param list<string> $contracts
     * @param list<mixed> $arguments
     */
    private function holdsAll(array $contracts, ReflectionMethod $method, array $arguments, mixed $result): bool
    {
        foreach ($contracts as $contract) {
            if (!$this->evaluate($contract, $method, $arguments, $result)) {
                return false;
            }
        }
        return true;
    }
    /**
     * A read of a key that does not exist is an error here: a contract that guards its reads
     * never triggers one, and one that does not would be a contract the proof read differently.
     *
     * @param list<mixed> $arguments
     */
    private function evaluate(string $contract, ReflectionMethod $method, array $arguments, mixed $result): bool
    {
        set_error_handler(static function (int $level, string $message): never {
            throw new RuntimeException($message);
        });
        try {
            return PhpContract::evaluate($contract, $method, null, $arguments, $result);
        } finally {
            restore_error_handler();
        }
    }
}
