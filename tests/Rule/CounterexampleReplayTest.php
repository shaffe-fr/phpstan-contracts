<?php

declare(strict_types=1);

namespace PhpstanContracts\Tests\Rule;

use PhpstanContracts\Tests\Fixtures\ReplayFixture;
use PhpstanContracts\Tests\Support\Diagnostic;
use PhpstanContracts\Tests\Support\RuleFixtureTestCase;
use ReflectionClass;
use ReflectionEnum;
use ReflectionMethod;
use ReflectionNamedType;
use ReflectionType;
use RuntimeException;
use TypeError;

/**
 * The promise of a counterexample is that it is real. For every refuted method
 * of the fixture, the values in the message are fed to the method in PHP, and PHP
 * must return what the message says and falsify the contract.
 */
final class CounterexampleReplayTest extends RuleFixtureTestCase
{
    public function testEveryReportedCounterexampleIsReproducedByPhp(): void
    {
        if (!function_exists('implies')) {
            eval('function implies(bool $premise, bool $conclusion): bool { return !$premise || $conclusion; }');
        }

        $diagnostics = $this->diagnose([ReplayFixture::class])[ReplayFixture::class];
        $replayed = 0;

        foreach ((new ReflectionClass(ReplayFixture::class))->getMethods(ReflectionMethod::IS_STATIC) as $method) {
            $found = $diagnostics[$method->getName()] ?? [];
            self::assertNotSame([], $found, $method->getName() . ' should have been refuted.');

            foreach ($found as $diagnostic) {
                $this->replay($method, $diagnostic);
                ++$replayed;
            }
        }

        self::assertGreaterThanOrEqual(18, $replayed);
    }

    private function replay(ReflectionMethod $method, Diagnostic $diagnostic): void
    {
        $label = $method->getName() . ': ' . $diagnostic->message;

        if ($diagnostic->identifier === 'contracts.overflow') {
            $arguments = $this->arguments($method, $this->between($diagnostic->message, 'Counterexample: ', '.'), $label);

            try {
                $method->invoke(null, ...$arguments);
                self::fail('PHP did not overflow. ' . $label);
            } catch (TypeError) {
                $this->addToAssertionCount(1);
            }

            return;
        }

        self::assertSame('contracts.ensuresViolated', $diagnostic->identifier, $label);

        $parts = $this->parseViolation($diagnostic->message);
        self::assertNotNull($parts, 'The message has an unexpected shape: ' . $label);
        [$contract, $inputs, $shownResult] = $parts;

        $arguments = $this->arguments($method, $inputs, $label);
        $actual = $method->invoke(null, ...$arguments);

        self::assertTrue(
            $this->same($this->decode($shownResult, $method->getReturnType(), $label), $actual),
            sprintf("PHP returns %s, the message says %s.\n%s", var_export($actual, true), $shownResult, $label),
        );

        self::assertFalse(
            $this->evaluateContract($contract, $method, $arguments, $actual),
            'The contract holds in PHP for the reported inputs, so the counterexample is not one. ' . $label,
        );
    }

    /**
     * @return array{string, string, string}|null the contract, the inputs and the result, as they are printed
     */
    private function parseViolation(string $message): ?array
    {
        $pattern = '/^Postcondition #\[Ensures\(\'(.*)\'\)\] can be violated\. Counterexample: (.*) => \$result = (.*) \(return at line \d+\)\.$/s';

        if (preg_match($pattern, $message, $matches) !== 1) {
            return null;
        }

        return [$matches[1], $matches[2], $matches[3]];
    }

    /**
     * @param list<mixed> $arguments
     */
    private function evaluateContract(string $contract, ReflectionMethod $method, array $arguments, mixed $result): bool
    {
        $names = array_map(static fn ($parameter): string => '$' . $parameter->getName(), $method->getParameters());
        $closure = eval(sprintf(
            'namespace PhpstanContracts\Tests\Fixtures; return static function (%s): bool { return (bool) (%s); };',
            implode(', ', [...$names, '$result']),
            $contract,
        ));

        return $closure(...$arguments, ...[$result]);
    }

    /**
     * @return list<mixed>
     */
    private function arguments(ReflectionMethod $method, string $inputs, string $label): array
    {
        $parameters = $method->getParameters();
        $values = [];

        foreach ($parameters as $index => $parameter) {
            $start = '$' . $parameter->getName() . ' = ';
            self::assertStringStartsWith($start, $inputs, 'Expected ' . $start . ' in ' . $label);
            $inputs = substr($inputs, strlen($start));

            $next = $parameters[$index + 1] ?? null;
            if ($next === null) {
                $literal = $inputs;
                $inputs = '';
            } else {
                $separator = strpos($inputs, ', $' . $next->getName() . ' = ');
                self::assertNotFalse($separator, 'Cannot find the next parameter in ' . $label);
                $literal = substr($inputs, 0, $separator);
                $inputs = substr($inputs, $separator + 2);
            }

            $values[] = $this->decode($literal, $parameter->getType(), $label);
        }

        return $values;
    }

    private function decode(string $literal, ?ReflectionType $type, string $label): mixed
    {
        $name = $type instanceof ReflectionNamedType ? $type->getName() : 'mixed';

        if ($literal === 'null') {
            return null;
        }

        if (enum_exists($name)) {
            return $this->decodeEnum($name, $literal);
        }

        return match (true) {
            $name === 'bool' => $literal === 'true',
            $name === 'int' => (int) $literal,
            $name === 'float' => match ($literal) {
                'NAN' => NAN,
                'INF' => INF,
                '-INF' => -INF,
                default => (float) $literal,
            },
            $name === 'string' => $this->decodeString($literal),
            default => throw new RuntimeException(sprintf('Cannot decode %s as %s. %s', $literal, $name, $label)),
        };
    }

    private function decodeString(string $literal): string
    {
        $body = substr($literal, 1, -1);

        if (str_starts_with($literal, "'")) {
            return str_replace(["\\'", '\\\\'], ["'", '\\'], $body);
        }

        return (string) preg_replace_callback(
            '/\\\\(x[0-9A-F]{2}|["\\\\$])/',
            static fn (array $match): string => $match[1][0] === 'x' ? chr((int) hexdec(substr($match[1], 1))) : $match[1],
            $body,
        );
    }

    /**
     * @param class-string<\UnitEnum> $class
     */
    private function decodeEnum(string $class, string $literal): mixed
    {
        [, $case] = explode('::', $literal, 2);

        return (new ReflectionEnum($class))->getCase($case)->getValue();
    }

    private function same(mixed $expected, mixed $actual): bool
    {
        if (is_float($expected) && is_float($actual) && is_nan($expected) && is_nan($actual)) {
            return true;
        }

        return $expected === $actual;
    }

    private function between(string $text, string $from, string $to): string
    {
        $start = strpos($text, $from);
        self::assertNotFalse($start);
        $start += strlen($from);
        $end = strrpos($text, $to);

        return substr($text, $start, $end === false ? null : $end - $start);
    }
}
