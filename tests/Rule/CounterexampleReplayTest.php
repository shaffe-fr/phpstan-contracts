<?php

declare(strict_types=1);

namespace PhpstanContracts\Tests\Rule;

use Closure;
use PhpstanContracts\Attribute\Throws;
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
use Throwable;
use TypeError;

/**
 * The promise of a counterexample is that it is real. For every refuted method
 * of the fixture, the values in the message are fed to the method in PHP, and PHP
 * must return what the message says and falsify the contract.
 *
 * An instance method of an enum is called on the case that the message gives
 * as `$this`. A precondition of a called method is evaluated on the reported
 * inputs, which the fixture passes to the callee under the same names. A throw
 * that no `#[Throws]` allows must be thrown by PHP, and a `#[Throws]` that the
 * method breaks must see PHP return although its condition holds.
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
                // Private methods are the helpers that the refuted methods call.
                if ($method->getDeclaringClass()->getName() !== $class || !$method->isUserDefined() || $method->isPrivate()) {
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

        if ($diagnostic->identifier === 'contracts.undefinedKey') {
            $this->replayUndefinedKey($method, $diagnostic, $label);

            return;
        }

        if ($diagnostic->identifier === 'contracts.requiresViolated') {
            $this->replayPrecondition($method, $diagnostic, $label);

            return;
        }

        if ($diagnostic->identifier === 'contracts.unexpectedThrow') {
            $this->replayUnexpectedThrow($method, $diagnostic, $label);

            return;
        }

        if ($diagnostic->identifier === 'contracts.throwsViolated') {
            $this->replayThrowsViolation($method, $diagnostic, $label);

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
     * PHP raises "Undefined array key" for the read, which the error handler of a framework
     * turns into an exception, so the replay turns it into one too.
     */
    private function replayUndefinedKey(ReflectionMethod $method, Diagnostic $diagnostic, string $label): void
    {
        [$object, $arguments] = $this->arguments($method, $this->between($diagnostic->message, 'Counterexample: ', '.'), $label);
        set_error_handler(static function (int $level, string $message): never {
            throw new RuntimeException($message);
        }, E_WARNING);
        try {
            $method->invoke($object, ...$arguments);
            self::fail('PHP read the key without a warning. ' . $label);
        } catch (RuntimeException $warning) {
            self::assertStringContainsString('Undefined array key', $warning->getMessage(), $label);
        } finally {
            restore_error_handler();
        }
    }

    private function replayPrecondition(ReflectionMethod $method, Diagnostic $diagnostic, string $label): void
    {
        if (preg_match('/#\[Requires\(\'(.*?)\'\)\]/s', $diagnostic->message, $matches) !== 1) {
            self::fail('The message names no precondition: ' . $label);
        }
        [$object, $arguments] = $this->arguments($method, $this->between($diagnostic->message, 'Counterexample: ', '.'), $label);

        self::assertFalse(
            $this->evaluateContract($matches[1], $method, $object, $arguments, null),
            'The precondition holds in PHP for the reported inputs. ' . $label,
        );
    }

    private function replayUnexpectedThrow(ReflectionMethod $method, Diagnostic $diagnostic, string $label): void
    {
        [$object, $arguments] = $this->arguments($method, $this->between($diagnostic->message, 'Counterexample: ', '.'), $label);

        $thrown = null;
        try {
            $method->invoke($object, ...$arguments);
        } catch (Throwable $exception) {
            $thrown = $exception;
        }
        self::assertNotNull($thrown, 'PHP did not throw. ' . $label);

        // What ThrowPolicy::allowed() computes, evaluated in PHP.
        $covered = false;
        $conditions = true;
        foreach ($method->getAttributes(Throws::class) as $attribute) {
            $throws = $attribute->newInstance();
            $holds = $throws->when === null ? null : $this->evaluateContract($throws->when, $method, $object, $arguments, null);
            if ($thrown instanceof $throws->class) {
                $covered = true;
                $conditions = $conditions && $holds !== false;
            } elseif ($holds === true) {
                $conditions = false;
            }
        }

        self::assertFalse($covered && $conditions, sprintf('PHP threw %s, which the #[Throws] allow. %s', $thrown::class, $label));
    }

    private function replayThrowsViolation(ReflectionMethod $method, Diagnostic $diagnostic, string $label): void
    {
        $pattern = '/^#\[Throws\(.*::class, when: \'(.*)\'\)\] is violated: the method can return although the condition holds\. Counterexample: (.*) => \$result = (.*) \(return at line \d+\)\.$/s';
        if (preg_match($pattern, $diagnostic->message, $matches) !== 1) {
            self::fail('The message has an unexpected shape: ' . $label);
        }
        [, $when, $inputs, $shownResult] = $matches;

        [$object, $arguments] = $this->arguments($method, $inputs, $label);
        $actual = $method->invoke($object, ...$arguments);

        self::assertTrue(
            $this->same($this->decode($shownResult, $this->typeName($method->getReturnType()), $label), $actual),
            sprintf("PHP returns %s, the message says %s.\n%s", var_export($actual, true), $shownResult, $label),
        );
        self::assertTrue(
            $this->evaluateContract($when, $method, $object, $arguments, $actual),
            'The condition of the #[Throws] does not hold in PHP for the reported inputs. ' . $label,
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
        if ($name === 'array') {
            $position = 0;
            $value = $this->parseValue($literal, $position, $label);
            $this->skipSpaces($literal, $position);
            self::assertSame(strlen($literal), $position, 'Trailing text after the array literal. ' . $label);

            return $value;
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

    /**
     * A recursive-descent reader for the literals the formatter prints, without eval:
     * lists and shapes, strings in both quote styles, null, booleans, NAN and INF, numbers,
     * and enum cases written as `Short::Case`, which belong to the namespace of the fixtures.
     */
    private function parseValue(string $text, int &$position, string $label): mixed
    {
        $this->skipSpaces($text, $position);
        $character = $text[$position] ?? '';
        if ($character === '[') {
            ++$position;
            $items = [];
            while (true) {
                $this->skipSpaces($text, $position);
                if (($text[$position] ?? '') === ']') {
                    ++$position;

                    return $items;
                }
                $value = $this->parseValue($text, $position, $label);
                $this->skipSpaces($text, $position);
                if (substr($text, $position, 2) === '=>') {
                    $position += 2;
                    self::assertTrue(is_string($value) || is_int($value), 'An array key must be a string or an int. ' . $label);
                    $items[$value] = $this->parseValue($text, $position, $label);
                } else {
                    $items[] = $value;
                }
                $this->skipSpaces($text, $position);
                if (($text[$position] ?? '') === ',') {
                    ++$position;
                }
            }
        }
        if ($character === "'" || $character === '"') {
            $start = $position++;
            while (isset($text[$position]) && $text[$position] !== $character) {
                $position += $text[$position] === '\\' ? 2 : 1;
            }
            ++$position;

            return $this->decodeString(substr($text, $start, $position - $start));
        }
        if (preg_match('/\G[A-Za-z0-9_.+\\\\:-]+/', $text, $token, 0, $position) !== 1) {
            self::fail('Cannot read a value at offset ' . $position . '. ' . $label);
        }
        $word = $token[0];
        $position += strlen($word);

        if (str_contains($word, '::')) {
            $enum = 'PhpstanContracts\\Tests\\Fixtures\\' . explode('::', $word, 2)[0];
            self::assertTrue(enum_exists($enum), 'Unknown enum in ' . $word . '. ' . $label);

            return $this->decodeEnum($enum, $word);
        }

        return match (true) {
            $word === 'null' => null,
            $word === 'true' => true,
            $word === 'false' => false,
            $word === 'NAN' => NAN,
            $word === 'INF' => INF,
            $word === '-INF' => -INF,
            strpbrk($word, '.E') !== false => (float) $word,
            default => (int) $word,
        };
    }

    private function skipSpaces(string $text, int &$position): void
    {
        while (isset($text[$position]) && $text[$position] === ' ') {
            ++$position;
        }
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
        // A shape is printed in the order of its declared type and built in the order of the literal.
        if (is_array($expected) && is_array($actual)) {
            if (!array_is_list($expected) || !array_is_list($actual)) {
                ksort($expected);
                ksort($actual);
            }
            if (array_keys($expected) !== array_keys($actual)) {
                return false;
            }
            foreach ($expected as $key => $value) {
                if (!$this->same($value, $actual[$key])) {
                    return false;
                }
            }

            return true;
        }
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
