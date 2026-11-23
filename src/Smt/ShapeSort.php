<?php

declare(strict_types=1);

namespace PhpstanContracts\Smt;

/**
 * An `array{a: T, b: U}` modelled as an SMT datatype with a single constructor whose
 * arguments are the fields in byte order of their keys, so that two shape types with
 * the same keys and field sorts are the same sort whatever order they are written in
 * (PHP accepts any key order for such a type).
 *
 * It is deliberately not a {@see RecordSort}: an array is never an object.
 */
final readonly class ShapeSort
{
    public string $smtName;

    /**
     * @param array<string, Sort> $fields key => sort, keys in byte order
     */
    public function __construct(public array $fields)
    {
        // Length-prefixed hex keys: injective for any byte string (json_encode fails on invalid UTF-8).
        $canonical = '';
        foreach ($fields as $key => $sort) {
            $canonical .= bin2hex((string) $key) . ':' . $sort->smt . ';';
        }
        $this->smtName = 'Shape_' . substr(sha1($canonical), 0, 10);
    }

    /**
     * @param array<string, Sort> $fields key => sort, in any order
     */
    public static function of(array $fields): self
    {
        uksort($fields, static fn (int|string $a, int|string $b): int => strcmp((string) $a, (string) $b));

        return new self($fields);
    }

    /**
     * @return list<string> the keys in canonical order
     */
    public function keys(): array
    {
        return array_map(strval(...), array_keys($this->fields));
    }

    public function constructorName(): string
    {
        return 'mk_' . $this->smtName;
    }

    public function accessor(string $key): string
    {
        $position = array_search($key, $this->keys(), true);

        return $this->accessorAt($position === false ? -1 : $position);
    }

    public function accessorAt(int $position): string
    {
        return $this->smtName . '_' . $position;
    }

    public function has(string $key): bool
    {
        return array_key_exists($key, $this->fields);
    }

    /**
     * `array{net: int, vat: int}`, with the keys in `$order` when given.
     *
     * @param list<string>|null $order
     */
    public function describe(?array $order = null): string
    {
        $parts = [];
        foreach ($order ?? $this->keys() as $key) {
            $field = $this->fields[$key] ?? null;
            if ($field === null) {
                continue;
            }
            $label = preg_match('/^[A-Za-z_][A-Za-z0-9_]*$/', $key) === 1
                ? $key
                : "'" . str_replace(['\\', "'"], ['\\\\', "\\'"], $key) . "'";
            $parts[] = $label . ': ' . $field->describe();
        }

        return 'array{' . implode(', ', $parts) . '}';
    }
}
