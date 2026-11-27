<?php

declare(strict_types=1);

namespace PhpstanContracts\Tests\Rule;

use PhpstanContracts\Solver\AbstractZ3Runner;
use PhpstanContracts\Solver\CheckRunner;
use PhpstanContracts\Solver\Clock;
use PhpstanContracts\Solver\ProofCache;
use PhpstanContracts\Solver\RunBudget;
use PhpstanContracts\Solver\Z3Runner;
use PhpstanContracts\Tests\Support\RuleFixtureTestCase;
use ReflectionProperty;

/**
 * With `contracts.persistentSolver` left at its default, the container still builds the disposable
 * runner, wired with the container's own proof cache, clock and run budget.
 */
final class SolverWiringTest extends RuleFixtureTestCase
{
    public function testTheDefaultIsTheDisposableRunnerWithItsSharedServices(): void
    {
        $container = self::getContainer();
        $runner = $container->getByType(CheckRunner::class);

        self::assertInstanceOf(Z3Runner::class, $runner);
        foreach ([ProofCache::class => 'cache', RunBudget::class => 'runBudget', Clock::class => 'clock'] as $service => $property) {
            self::assertSame(
                $container->getByType($service),
                (new ReflectionProperty(AbstractZ3Runner::class, $property))->getValue($runner),
                sprintf('The runner must use the container\'s %s.', $service),
            );
        }
    }
}
