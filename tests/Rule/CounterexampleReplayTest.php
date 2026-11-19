<?php

declare(strict_types=1);

namespace PhpstanContracts\Tests\Rule;

use Closure;
use PhpstanContracts\Tests\Fixtures\ReplayFixture;
use PhpstanContracts\Tests\Fixtures\ReplayStatus;
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
 *
 * An instance method of an enum is called on the case that the message gives
 * as `$this`.
 */
final class CounterexampleReplayTest extends RuleFixtureTestCase
{
    public function testEveryReportedCounterexampleIsReproducedByPhp(): void
    {
        if (!function_exists('implies')) {
            eval('function implies(bool $premise, bool $conclusion): bool { return !$premise || $conclusion; }');
        }

        $classes = [ReplayFixture::class, ReplayStatus::class];
        $diagnostics = $this->diagnose($classes);
        $replayed = 0;

        foreach ($classes as $class) {
            foreach ((new ReflectionClass($class))->getMethods() as $method) {
                if ($method->getDeclaringClass()->getName() !== $class || !$method->isUserDefined()) {
                    continue;
                }

                $found = $diagnostics[$class][$method->getName()] ?? [];
                self::assertNotSame([], $found, $method->getName() . ' should have been refuted.');

                foreach ($found as $diagnostic) {
                    $this->replay($method, $diagnostic);
                    ++$replayed;
                }
            }
        }

        self::assertGreaterThanOrEqual(20, $replayed);
    }

    private function replay(ReflectionMethod $method, Diagnostic $diagnostic): void
    {
        $label = $method->getName() . ': ' . $diagnostic->message;

        if ($diagnostic->identifier === 'contracts.overflow') {
            [$object, $arguments] = $this->arguments($method, $this->between($diagnostic->message, 'Counterexample: ', '.'), $label);

            try {
                $method->invoke($object, ...$arguments);
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

        [$object, $arguments] = $this->arguments($method, $inputs, $label);
        $actual = $method->invoke($object, ...$arguments);

        self::assertTrue(
            $this->same($this->decode($shownResult, $this->typeName($method->getReturnType()), $label), $actual),
            sprintf("PHP returns %s, the message says %s.\n%s", var_export($actual, true), $shownResult, $label),
        );

        self::assertFalse(
            $this->evaluateContract($contract, $method, $object, $arguments, $actual),
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
     * The contract is evaluated in the scope of the class, so that `self::` resolves,
     * with `$this` passed as an ordinary parameter.
     *
     * @param list<mixed> $arguments
     */
    private function evaluateContract(string $contract, ReflectionMethod $method, ?object $object, array $arguments, mixed $result): bool
    {
        $names = array_map(static fn ($parameter): string => '$' . $parameter->getName(), $method->getParameters());
        $closure = eval(sprintf(
            'namespace PhpstanContracts\Tests\Fixtures; return static function (%s): bool { return (bool) (%s); };',
            implode(', ', ['$self', ...$names, '$result']),
            str_replace('$this', '$self', $contract),
        ));
        $closure = Closure::bind($closure, null, $method->getDeclaringClass()->getName());
        self::assertNotNull($closure);

        return $closure($object, ...$arguments, ...[$result]);
    }

    /**
     * @return array{object|null, list<mixed>} the object to call the method on, and the arguments
     */
    private function arguments(ReflectionMethod $method, string $inputs, string $label): array
    {
        /** @var list<array{string, string}> $parameters */
        $parameters = array_map(
            fn ($parameter): array => [$parameter->getName(), $this->typeName($parameter->getType())],
            $method->getParameters(),
        );
        if (!$method->isStatic()) {
            array_unshift($parameters, ['this', $method->getDeclaringClass()->getName()]);
        }

        $values = [];
        foreach ($parameters as $index => [$name, $type]) {
            $start = '$' . $name . ' = ';
            self::assertStringStartsWith($start, $inputs, 'Expected ' . $start . ' in ' . $label);
            $inputs = substr($inputs, strlen($start));

            $next = $parameters[$index + 1] ?? null;
            if ($next === null) {
                $literal = $inputs;
                $inputs = '';
            } else {
                $separator = strpos($inputs, ', $' . $next[0] . ' = ');
                self::assertNotFalse($separator, 'Cannot find the next parameter in ' . $label);
                $literal = substr($inputs, 0, $separator);
                $inputs = substr($inputs, $separator + 2);
            }

            $values[] = $this->decode($literal, $type, $label);
        }

        if ($method->isStatic()) {
            return [null, $values];
        }

        $object = array_shift($values);
        self::assertIsObject($object);

        return [$object, $values];
    }

    private function typeName(?ReflectionType $type): string
    {
        return $type instanceof ReflectionNamedType ? $type->getName() : 'mixed';
    }

    private function decode(string $literal, string $name, string $label): mixed
    {
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
