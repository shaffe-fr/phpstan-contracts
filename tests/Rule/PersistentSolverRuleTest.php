<?php

declare(strict_types=1);

namespace PhpstanContracts\Tests\Rule;

use PhpstanContracts\Solver\AbstractZ3Runner;
use PhpstanContracts\Solver\CheckRunner;
use PhpstanContracts\Solver\Clock;
use PhpstanContracts\Solver\PersistentZ3Runner;
use PhpstanContracts\Solver\ProofCache;
use PhpstanContracts\Solver\RunBudget;
use PhpstanContracts\Tests\Fixtures\IntegerFixture;
use PhpstanContracts\Tests\Support\RuleFixtureTestCase;
use ReflectionProperty;

/**
 * The same fixture and the same expectations as {@see IntegerRuleTest} (which runs the disposable
 * runner), but with `contracts.persistentSolver: true`, so the scripts of the plain runner are asked
 * of one persistent Z3 process. Passing against the identical expectations is the differential proof
 * that the persistent path reports the same messages the package has always reported.
 */
final class PersistentSolverRuleTest extends RuleFixtureTestCase
{
    protected static function configFile(): string
    {
        return 'persistent.neon';
    }

    public function testTheContainerBuildsThePersistentRunnerWithItsSharedServices(): void
    {
        $container = self::getContainer();
        $runner = $container->getByType(CheckRunner::class);

        self::assertInstanceOf(PersistentZ3Runner::class, $runner);
        // A runner built with fresh copies would silently have no proof cache directory and no run budget limit.
        foreach ([ProofCache::class => 'cache', RunBudget::class => 'runBudget', Clock::class => 'clock'] as $service => $property) {
            self::assertSame(
                $container->getByType($service),
                (new ReflectionProperty(AbstractZ3Runner::class, $property))->getValue($runner),
                sprintf('The runner must use the container\'s %s.', $service),
            );
        }
    }

    public function testPersistentRunnerReportsTheSameDiagnostics(): void
    {
        $this->assertOutcomes([
            IntegerFixture::class => [
                // Proven.
                'sum' => [],
                'earlyReturn' => [],
                'elseIfChain' => [],
                'entryValuesSurviveReassignment' => [],
                'incrementStatements' => [],
                'decrementStatements' => [],
                'incrementWrongResult' => ['contracts.ensuresViolated'],
                'ternary' => [],
                'matchWithDefault' => [],
                'matchTrue' => [],
                'divisionAndModuloObeyTheLawsOfTruncatedDivision' => [],
                'maximumOfTwo' => [],
                'minimumOfTwo' => [],
                'absoluteValue' => [],
                'divisionGuardedByAnd' => [],
                'divisionGuardedByTernary' => [],
                'implication' => [],
                'pureOnlyIsProvenSafe' => [],
                'requiresRelatesParameters' => [],
                'returnRangeIsRespected' => [],

                // A contract that can be violated.
                'postconditionViolated' => ['contracts.ensuresViolated: Postcondition #[Ensures(\'$result > 50\')] can be violated'],
                'onlyTheFailingClauseIsReported' => ['contracts.ensuresViolated: $result > 100'],
                'returnRangeIsViolated' => ['contracts.returnType: `int<0, 10>`'],

                // The automatic safety checks.
                'additionOverflows' => ['contracts.overflow: `$a + $b`'],
                'multiplicationOverflows' => ['contracts.overflow: `$a * $b`'],
                'negationOverflows' => ['contracts.overflow: `-$a`'],
                'absoluteValueOverflows' => ['contracts.overflow: `abs($a)`'],
                'divisionByZero' => ['contracts.divisionByZero: `intdiv($a, $b)`'],
                'moduloByZero' => ['contracts.divisionByZero: `$a % $b`'],
                'minimumDividedByMinusOneOverflows' => ['contracts.divisionByZero', 'contracts.overflow: `intdiv($a, $b)`'],
                'guardOfTheWrongOperand' => ['contracts.divisionByZero'],
                'matchDoesNotCoverEveryValue' => ['contracts.unhandledMatch'],
                'pathWithoutReturn' => ['contracts.missingReturn: Not every code path returns a value'],
                'contradictoryPreconditions' => ['contracts.contradictoryRequires'],
            ],
        ]);
    }
}
