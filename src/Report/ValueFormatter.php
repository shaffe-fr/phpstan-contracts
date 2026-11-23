<?php

declare(strict_types=1);

namespace PhpstanContracts\Report;

use PhpstanContracts\Smt\Sort;
use PhpstanContracts\Smt\SortKind;
use PhpstanContracts\Translate\ProbePlanner;

/**
 * Turns a value read back from Z3 (an S-expression) into the PHP the user would write.
 */
final class ValueFormatter
{
    /**
     * @param string|list<mixed>|null $raw
     * @param string $term the SMT term whose value `$raw` is, which names the probes of the lists inside it
     * @param array<string, string|list<mixed>> $probes the answers of Z3 by term, see {@see ProbePlanner}
     */
    public function format(Sort $sort, string|array|null $raw, string $term = '', array $probes = []): string
    {
        if ($raw === null) {
            return 'any';
        }

        return match ($sort->kind) {
            SortKind::Int => $this->integer($raw),
            SortKind::Bool => $raw === 'true' ? 'true' : 'false',
            SortKind::Float => $this->float($raw),
            SortKind::String => $this->string(is_string($raw) ? $raw : ''),
            SortKind::Enum => $sort->enum?->displayConstructor(is_string($raw) ? $raw : '') ?? 'enum',
            SortKind::Nullable => $this->nullable($sort, $raw, $term, $probes),
            SortKind::Record => $this->record($sort, $raw, $term, $probes),
            SortKind::Shape => $this->shape($sort, $raw, $term, $probes),
            SortKind::List => $this->list($sort, $raw, $term, $probes),
            SortKind::EmptyList => '[]',
            SortKind::Null => 'null',
            SortKind::Never => 'never',
        };
    }

    /**
     * @param string|array<mixed> $raw
     */
    private function integer(string|array $raw): string
    {
        if (is_array($raw) && count($raw) === 2 && $raw[0] === '-' && is_string($raw[1])) {
            return '-' . $raw[1];
        }

        return is_string($raw) ? $raw : '?';
    }

    /**
     * @param string|list<mixed> $raw
     * @param array<string, string|list<mixed>> $probes
     */
    private function nullable(Sort $sort, string|array $raw, string $term, array $probes): string
    {
        if (!is_array($raw) || $sort->inner === null) {
            return 'null';
        }

        /** @var string|list<mixed>|null $payload */
        $payload = $raw[1] ?? null;

        return $this->format($sort->inner, $payload, $term === '' ? '' : ProbePlanner::payload($term, $sort), $probes);
    }

    /**
     * @param string|list<mixed> $raw
     * @param array<string, string|list<mixed>> $probes
     */
    private function record(Sort $sort, string|array $raw, string $term, array $probes): string
    {
        if (!is_array($raw) || $sort->record === null) {
            return $sort->describe();
        }

        $parts = [];
        $position = 1;
        foreach ($sort->record->fields as $name => $fieldSort) {
            /** @var string|list<mixed>|null $value */
            $value = $raw[$position++] ?? null;
            $fieldTerm = $term === '' ? '' : sprintf('(%s %s)', $sort->record->accessor((string) $name), $term);
            $parts[] = sprintf('%s: %s', $name, $this->format($fieldSort, $value, $fieldTerm, $probes));
        }

        return sprintf('%s(%s)', $sort->record->shortName(), implode(', ', $parts));
    }

    /**
     * `['total' => 1, 'label' => 'x']`, the keys in the order of the declared type.
     *
     * @param string|list<mixed> $raw
     * @param array<string, string|list<mixed>> $probes
     */
    private function shape(Sort $sort, string|array $raw, string $term, array $probes): string
    {
        $definition = $sort->shape;
        if (!is_array($raw) || $definition === null) {
            return $sort->describe();
        }

        $values = [];
        foreach (array_values($definition->fields) as $position => $fieldSort) {
            $key = $definition->keys()[$position];
            /** @var string|list<mixed>|null $value */
            $value = $raw[$position + 1] ?? null;
            $fieldTerm = $term === '' ? '' : sprintf('(%s %s)', $definition->accessorAt($position), $term);
            $values[$key] = $this->format($fieldSort, $value, $fieldTerm, $probes);
        }

        $parts = [];
        foreach ($sort->displayOrder ?? $definition->keys() as $key) {
            if (isset($values[$key])) {
                $parts[] = sprintf('%s => %s', $this->quote($key), $values[$key]);
            }
        }

        return '[' . implode(', ', $parts) . ']';
    }

    /**
     * `[51, 3]`, or `a list of 28224 elements starting with [3, 3, ...]` when the list is
     * longer than what is probed: that form is not a PHP literal.
     *
     * @param string|list<mixed> $raw
     * @param array<string, string|list<mixed>> $probes
     */
    private function list(Sort $sort, string|array $raw, string $term, array $probes): string
    {
        $element = $sort->element;
        if (!is_array($raw) || $element === null) {
            return $sort->describe();
        }

        $length = $this->integer(is_array($raw[1] ?? null) || is_string($raw[1] ?? null) ? $raw[1] : '?');
        if ($term === '' || preg_match('/^-?\d+$/', $length) !== 1) {
            return $length === '0' ? '[]' : $sort->describe();
        }

        $shown = [];
        $count = min((int) $length, ProbePlanner::MAX_ELEMENTS);
        for ($index = 0; $index < $count; ++$index) {
            $itemTerm = ProbePlanner::item($term, $sort, $index);
            $shown[] = $this->format($element, $probes[$itemTerm] ?? null, $itemTerm, $probes);
        }

        if ((int) $length > ProbePlanner::MAX_ELEMENTS) {
            return sprintf('a list of %s elements starting with [%s]', $length, implode(', ', $shown));
        }

        return '[' . implode(', ', $shown) . ']';
    }

    /**
     * @param string|list<mixed> $raw
     */
    private function float(string|array $raw): string
    {
        if (!is_array($raw)) {
            return '?';
        }

        if (($raw[0] ?? null) === '_') {
            return match ($raw[1] ?? null) {
                'NaN' => 'NAN',
                '+oo' => 'INF',
                '-oo' => '-INF',
                '+zero' => '0.0',
                '-zero' => '-0.0',
                default => '?',
            };
        }

        if (($raw[0] ?? null) === 'fp' && count($raw) === 4) {
            $sign = $this->bits($raw[1]);
            $exponent = $this->bits($raw[2]);
            $mantissa = $this->bits($raw[3]);
            $pattern = ($sign << 63) | ($exponent << 52) | $mantissa;
            $unpacked = unpack('E', pack('J', $pattern));

            return is_array($unpacked) ? var_export($unpacked[1], true) : '?';
        }

        return '?';
    }

    private function bits(mixed $literal): int
    {
        if (!is_string($literal)) {
            return 0;
        }

        return match (true) {
            str_starts_with($literal, '#b') => (int) bindec(substr($literal, 2)),
            str_starts_with($literal, '#x') => (int) hexdec(substr($literal, 2)),
            default => 0,
        };
    }

    /**
     * Z3 prints a string literal with `""` for a quote and `\u{h}` for non-printable bytes.
     */
    private function string(string $literal): string
    {
        $body = substr($literal, 1, -1);
        $body = str_replace('""', '"', $body);
        $bytes = preg_replace_callback(
            '/\\\\u\{([0-9a-fA-F]+)\}/',
            static fn (array $m): string => hexdec($m[1]) <= 255 ? chr((int) hexdec($m[1])) : '?',
            $body,
        ) ?? $body;

        return $this->quote($bytes);
    }

    /**
     * The PHP literal for a byte string.
     */
    private function quote(string $bytes): string
    {
        if (preg_match('/^[\x20-\x7E]*$/', $bytes) === 1) {
            return "'" . str_replace(['\\', "'"], ['\\\\', "\\'"], $bytes) . "'";
        }

        $escaped = '';
        foreach (str_split($bytes) as $character) {
            $code = ord($character);
            $escaped .= match (true) {
                $character === '"' || $character === '\\' || $character === '$' => '\\' . $character,
                $code >= 0x20 && $code <= 0x7E => $character,
                default => sprintf('\\x%02X', $code),
            };
        }

        return '"' . $escaped . '"';
    }
}
