<?php

declare(strict_types=1);

namespace PhpstanContracts\Tests\Support;

use PhpstanContracts\Rule\VerifyContractRule;
use PHPStan\Rules\Rule;
use PHPStan\Testing\RuleTestCase;
use ReflectionClass;

/**
 * Runs the real rule, wired by the real `extension.neon`, over fixture classes
 * and compares the outcome of each method with what the fixture says it should be.
 *
 * Expectations are written per method, not per line, so that editing a fixture
 * does not break unrelated assertions. An empty list means "proven". An entry is
 * a diagnostic identifier, optionally followed by `: ` and a fragment that the
 * message must contain.
 *
 * @extends RuleTestCase<VerifyContractRule>
 */
abstract class RuleFixtureTestCase extends RuleTestCase
{
    use RequiresZ3;

    /**
     * @return list<string>
     */
    public static function getAdditionalConfigFiles(): array
    {
        return [__DIR__ . '/../../extension.neon', __DIR__ . '/../config/' . static::configFile()];
    }

    protected static function configFile(): string
    {
        return 'default.neon';
    }

    protected function getRule(): Rule
    {
        return self::getContainer()->getByType(VerifyContractRule::class);
    }

    protected function setUp(): void
    {
        parent::setUp();
        $this->requireZ3();
    }

    /**
     * @param array<class-string, array<string, list<string>>> $expectations class => method => expected diagnostics
     */
    protected function assertOutcomes(array $expectations): void
    {
        $actual = $this->diagnose(array_keys($expectations));

        foreach ($expectations as $class => $methods) {
            $declared = $this->declaredMethods($class);
            self::assertEqualsCanonicalizing(
                $declared,
                array_keys($methods),
                sprintf('Every method of %s needs an expectation, and every expectation a method.', $class),
            );

            foreach ($methods as $method => $expected) {
                $this->assertMatches($class . '::' . $method, $expected, $actual[$class][$method] ?? []);
            }
        }
    }

    /**
     * @param list<class-string> $classes
     * @return array<class-string, array<string, list<Diagnostic>>>
     */
    protected function diagnose(array $classes): array
    {
        $files = [];
        foreach ($classes as $class) {
            $files[] = (string) (new ReflectionClass($class))->getFileName();
        }

        $errors = $this->gatherAnalyserErrors(array_values(array_unique($files)));

        $byClass = [];
        foreach ($classes as $class) {
            $byClass[$class] = [];
        }

        foreach ($errors as $error) {
            // Only this rule runs here, so an ignore comment aimed at another rule looks unused.
            if (str_starts_with((string) $error->getIdentifier(), 'ignore.')) {
                continue;
            }

            $line = $error->getLine() ?? 0;
            $diagnostic = new Diagnostic($line, (string) $error->getIdentifier(), $error->getMessage());
            [$class, $method] = $this->locate($classes, $error->getFilePath(), $line);
            $byClass[$class][$method][] = $diagnostic;
        }

        return $byClass;
    }

    /**
     * @param list<string> $expected
     * @param list<Diagnostic> $actual
     */
    private function assertMatches(string $label, array $expected, array $actual): void
    {
        $remaining = $actual;

        foreach ($expected as $entry) {
            [$identifier, $fragment] = array_pad(explode(': ', $entry, 2), 2, null);

            $found = null;
            foreach ($remaining as $index => $diagnostic) {
                if ($diagnostic->identifier === $identifier && ($fragment === null || str_contains($diagnostic->message, $fragment))) {
                    $found = $index;
                    break;
                }
            }

            self::assertNotNull($found, sprintf(
                "%s: expected `%s` but got:\n%s",
                $label,
                $entry,
                $this->render($actual),
            ));
            unset($remaining[$found]);
        }

        self::assertSame([], array_values($remaining), sprintf(
            "%s: unexpected diagnostics:\n%s",
            $label,
            $this->render(array_values($remaining)),
        ));
    }

    /**
     * @param list<Diagnostic> $diagnostics
     */
    private function render(array $diagnostics): string
    {
        return $diagnostics === []
            ? '  (none)'
            : implode("\n", array_map(static fn (Diagnostic $d): string => '  ' . $d->describe(), $diagnostics));
    }

    /**
     * @param class-string $class
     * @return list<string>
     */
    private function declaredMethods(string $class): array
    {
        $names = [];
        foreach ((new ReflectionClass($class))->getMethods() as $method) {
            // An enum also declares cases(), from() and tryFrom(), which PHP provides.
            if ($method->getDeclaringClass()->getName() === $class && !$method->isConstructor() && $method->isUserDefined()) {
                $names[] = $method->getName();
            }
        }

        return $names;
    }

    /**
     * Finds the method whose body (or attributes, which sit just before it) holds a line.
     *
     * @param list<class-string> $classes
     * @return array{class-string, string}
     */
    private function locate(array $classes, string $file, int $line): array
    {
        $candidates = [];
        foreach ($classes as $class) {
            $reflection = new ReflectionClass($class);
            if (realpath((string) $reflection->getFileName()) !== realpath($file)) {
                continue;
            }
            foreach ($reflection->getMethods() as $method) {
                if ($method->getDeclaringClass()->getName() === $class && $method->isUserDefined()) {
                    $candidates[] = [$class, $method->getName(), (int) $method->getEndLine()];
                }
            }
        }

        usort($candidates, static fn (array $a, array $b): int => $a[2] <=> $b[2]);
        foreach ($candidates as [$class, $method, $end]) {
            if ($end >= $line) {
                return [$class, $method];
            }
        }

        self::fail(sprintf('A diagnostic at %s:%d does not belong to any method of the fixtures.', $file, $line));
    }
}
