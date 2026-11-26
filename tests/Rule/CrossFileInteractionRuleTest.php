<?php

declare(strict_types=1);

namespace PhpstanContracts\Tests\Rule;

use PhpstanContracts\Tests\Fixtures\ConstantInitializers;
use PhpstanContracts\Tests\Fixtures\CrossFile\Callees;
use PhpstanContracts\Tests\Fixtures\CrossFile\CrossFileInteractions;
use PhpstanContracts\Tests\Fixtures\CrossFile\StatelessOverOnce;
use PhpstanContracts\Tests\Fixtures\CrossFile\StatelessOverTwice;
use PhpstanContracts\Tests\Support\Diagnostic;
use PhpstanContracts\Tests\Support\RuleFixtureTestCase;

/**
 * The rules of loops, exception factories, `$this` without state and constant guards apply
 * to the body of a method of another file as they do to the same code in the verified method.
 */
final class CrossFileInteractionRuleTest extends RuleFixtureTestCase
{
    private const CALLEES = 'PhpstanContracts\Tests\Fixtures\CrossFile\Callees';

    public function testACalleeOfAnotherFileIsHeldToTheRulesOfTheCaller(): void
    {
        // ConstantInitializers.php is analysed too, so that the constants and enums it declares are found.
        $actual = $this->diagnose([CrossFileInteractions::class, Callees::class, ConstantInitializers::class, StatelessOverTwice::class, StatelessOverOnce::class]);
        $expectations = [
            CrossFileInteractions::class => [
                'costlySplitInACallee' => [],
                'boundedLoopInACallee' => [],
                // Reported at the call, which is in this file, not at the foreach of the callee.
                'unboundedLoopInACallee' => ['contracts.loopBound: The proof unrolls this foreach up to 10 iterations, and $cents can have more. This is a limit of the proof, not a bug in the code. If the real maximum is known, add #[Requires(\'count($cents) <= 50\')]; otherwise raise contracts.loopUnroll, in the call to ' . self::CALLEES . '::unboundedSum().'],
                'nonLinearCallee' => [],
                'factoryOfAnotherFile' => [],
                'calleeThrowsThroughAFactory' => [],
                'calleeThrowsThroughAFactoryUndeclared' => ['contracts.unexpectedThrow: `throw \PhpstanContracts\Tests\Fixtures\CrossFile\Refusals::negative()` can be reached where no #[Throws] allows it, in the call to ' . self::CALLEES . '::guard(). Counterexample: $x = '],
                'calleeThrowsThroughAFactoryTooNarrow' => ['contracts.unexpectedThrow: they allow it only where #[Throws(PhpstanContracts\Tests\Fixtures\CrossFile\NegativeRefusal::class, when: \'$x < -1\')] holds, in the call to ' . self::CALLEES . '::guard(). Counterexample: $x = '],
                'nonFinalFactoryHere' => ['contracts.unsupported: PhpstanContracts\Tests\Fixtures\CrossFile\Refusals::open() returns PhpstanContracts\Tests\Fixtures\CrossFile\OpenRefusal, which is not final'],
                // The callee has no #[Throws] of its own: the declarations of the verified method decide.
                'nonFinalFactoryInACallee' => ['contracts.unsupported: The call to ' . self::CALLEES . '::guardOpen() cannot be verified: PhpstanContracts\Tests\Fixtures\CrossFile\Refusals::open() returns PhpstanContracts\Tests\Fixtures\CrossFile\OpenRefusal, which is not final'],
                'deprecatedConstantHere' => ['contracts.unsupported: Constant PhpstanContracts\Tests\Fixtures\DeprecatedLimits::LIMIT is deprecated'],
                'deprecatedConstantInACallee' => ['contracts.unsupported: The call to ' . self::CALLEES . '::deprecatedConstant() cannot be verified: Constant PhpstanContracts\Tests\Fixtures\DeprecatedLimits::LIMIT is deprecated'],
                'deprecatedCaseHere' => ['contracts.unsupported: Enum case PhpstanContracts\Tests\Fixtures\Mode::Legacy is deprecated'],
                'deprecatedCaseInACallee' => ['contracts.unsupported: The call to ' . self::CALLEES . '::deprecatedCase() cannot be verified: Enum case PhpstanContracts\Tests\Fixtures\Mode::Legacy is deprecated'],
                'floatLabelHere' => ['contracts.unsupported: An initializer in PhpstanContracts\Tests\Fixtures\ConstantInitializers converts a float to a string'],
                'floatLabelInACallee' => ['contracts.unsupported: The call to ' . self::CALLEES . '::floatLabel() cannot be verified: An initializer in PhpstanContracts\Tests\Fixtures\ConstantInitializers converts a float to a string'],
                'floatBackingHere' => ['contracts.unsupported: FloatLabel::from() is not supported: An initializer in PhpstanContracts\Tests\Fixtures\FloatLabel converts a float to a string'],
                'floatBackingInACallee' => ['contracts.unsupported: The call to ' . self::CALLEES . '::floatBacking() cannot be verified: FloatLabel::from() is not supported: An initializer in PhpstanContracts\Tests\Fixtures\FloatLabel converts a float to a string'],
                'constantBoundHere' => [],
                'constantBoundInACallee' => [],
            ],
            // PHP returns 2: the parent that runs is the second declaration.
            StatelessOverTwice::class => ['viaThis' => ['contracts.unsupported: PhpstanContracts\Tests\Fixtures\CrossFile\TwiceParent::value() cannot be verified: PhpstanContracts\Tests\Fixtures\CrossFile\TwiceParent is declared more than once in ']],
            StatelessOverOnce::class => ['viaThis' => []],
        ];

        foreach ($expectations as $class => $methods) {
            foreach ($methods as $method => $expected) {
                $diagnostics = $actual[$class][$method] ?? [];
                self::assertCount(count($expected), $diagnostics, $class . '::' . $method . ":\n" . $this->describe($diagnostics));
                foreach ($expected as $index => $entry) {
                    [$identifier, $fragment] = explode(': ', $entry, 2);
                    self::assertSame($identifier, $diagnostics[$index]->identifier, $class . '::' . $method);
                    self::assertStringContainsString($fragment, $diagnostics[$index]->message, $class . '::' . $method);
                }
            }
        }

        $source = (array) file((string) (new \ReflectionClass(CrossFileInteractions::class))->getFileName(), FILE_IGNORE_NEW_LINES);
        $call = array_search("        return Callees::unboundedSum(\$cents);", $source, true);
        self::assertIsInt($call);
        self::assertSame($call + 1, $actual[CrossFileInteractions::class]['unboundedLoopInACallee'][0]->line);
    }

    /**
     * @param list<Diagnostic> $diagnostics
     */
    private function describe(array $diagnostics): string
    {
        return implode("\n", array_map(static fn (Diagnostic $diagnostic): string => $diagnostic->describe(), $diagnostics));
    }
}
