<?php

declare(strict_types=1);

namespace PhpstanContracts\Tests\Support;

use PhpstanContracts\Smt\SExpr;
use PhpstanContracts\Solver\ScriptRenderer;
use ReflectionClassConstant;
use RuntimeException;
use Symfony\Component\Process\Process;

/**
 * Asks Z3 to evaluate closed terms, with the same prelude as the real scripts.
 * Used to compare the SMT meaning of an operation with what PHP computes.
 */
final class Z3Evaluator
{
    public function __construct(private readonly string $binary)
    {
    }

    /**
     * @param list<string> $terms closed SMT-LIB terms
     * @param list<string> $declarations datatype declarations the terms need, as ScriptRenderer::declarations() writes them
     * @return list<string|list<mixed>> the simplified value of each term, in order
     */
    public function evaluate(array $terms, array $declarations = []): array
    {
        $prelude = (new ReflectionClassConstant(ScriptRenderer::class, 'PRELUDE'))->getValue();
        $script = implode("\n", $declarations) . "\n" . $prelude . "\n" . implode("\n", array_map(static fn (string $term): string => '(simplify ' . $term . ')', $terms)) . "\n";

        $process = new Process([$this->binary, '-in', '-smt2']);
        $process->setInput($script);
        $process->setTimeout(120);
        $process->run();

        $values = SExpr::parse($process->getOutput());
        if (count($values) !== count($terms)) {
            throw new RuntimeException(sprintf(
                "Z3 returned %d values for %d terms.\n%s",
                count($values),
                count($terms),
                substr($process->getOutput() . $process->getErrorOutput(), 0, 600),
            ));
        }

        return $values;
    }

    /**
     * @param string|list<mixed> $value
     */
    public static function integer(string|array $value): string
    {
        if (is_array($value) && count($value) === 2 && $value[0] === '-' && is_string($value[1])) {
            return '-' . $value[1];
        }

        return is_string($value) ? $value : json_encode($value, JSON_THROW_ON_ERROR);
    }

    /**
     * The bit pattern of an SMT floating-point value, as hexadecimal, with one pattern for every NaN.
     *
     * @param string|list<mixed> $value
     */
    public static function floatBits(string|array $value): string
    {
        if (is_array($value) && ($value[0] ?? null) === '_') {
            return match ($value[1] ?? null) {
                'NaN' => 'nan',
                '+oo' => bin2hex(pack('E', INF)),
                '-oo' => bin2hex(pack('E', -INF)),
                '+zero' => bin2hex(pack('E', 0.0)),
                '-zero' => bin2hex(pack('E', -0.0)),
                default => throw new RuntimeException('Unknown float value ' . json_encode($value)),
            };
        }

        if (is_array($value) && ($value[0] ?? null) === 'fp') {
            $bits = static fn (string $literal): int => str_starts_with($literal, '#b')
                ? (int) bindec(substr($literal, 2))
                : (int) hexdec(substr($literal, 2));

            return bin2hex(pack('J', ($bits((string) $value[1]) << 63) | ($bits((string) $value[2]) << 52) | $bits((string) $value[3])));
        }

        throw new RuntimeException('Not a float value: ' . json_encode($value));
    }

    public static function phpFloatBits(float $value): string
    {
        return is_nan($value) ? 'nan' : bin2hex(pack('E', $value));
    }
}
