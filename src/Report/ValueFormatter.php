<?php

declare(strict_types=1);

namespace PhpstanContracts\Report;

use PhpstanContracts\Smt\Sort;
use PhpstanContracts\Smt\SortKind;

/**
 * Turns a value read back from Z3 (an S-expression) into the PHP the user would write.
 */
final class ValueFormatter
{
    /**
     * @param string|list<mixed>|null $raw
     */
    public function format(Sort $sort, string|array|null $raw): string
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
            SortKind::Nullable => $this->nullable($sort, $raw),
            SortKind::Record => $this->record($sort, $raw),
            SortKind::Null => 'null',
            SortKind::Never => 'never',
        };
    }

    /**
     * @param string|list<mixed> $raw
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
     */
    private function nullable(Sort $sort, string|array $raw): string
    {
        if (!is_array($raw) || $sort->inner === null) {
            return 'null';
        }

        /** @var string|list<mixed>|null $payload */
        $payload = $raw[1] ?? null;

        return $this->format($sort->inner, $payload);
    }

    /**
     * @param string|list<mixed> $raw
     */
    private function record(Sort $sort, string|array $raw): string
    {
        if (!is_array($raw) || $sort->record === null) {
            return $sort->describe();
        }

        $parts = [];
        $position = 1;
        foreach ($sort->record->fields as $name => $fieldSort) {
            /** @var string|list<mixed>|null $value */
            $value = $raw[$position++] ?? null;
            $parts[] = sprintf('%s: %s', $name, $this->format($fieldSort, $value));
        }

        return sprintf('%s(%s)', $sort->record->shortName(), implode(', ', $parts));
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
