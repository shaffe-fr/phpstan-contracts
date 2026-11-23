<?php

declare(strict_types=1);

namespace PhpstanContracts\Smt;

final readonly class Sort
{
    private function __construct(
        public SortKind $kind,
        public string $smt,
        public ?EnumSort $enum = null,
        public ?Sort $inner = null,
        public ?RecordSort $record = null,
        public ?Sort $element = null,
        public ?ShapeSort $shape = null,
        /** @var list<string>|null for a shape, its keys in the order of the declared type it comes from */
        public ?array $displayOrder = null,
    ) {
    }

    public static function int(): self
    {
        return new self(SortKind::Int, 'Int');
    }

    public static function bool(): self
    {
        return new self(SortKind::Bool, 'Bool');
    }

    public static function float(): self
    {
        return new self(SortKind::Float, 'Float64');
    }

    public static function string(): self
    {
        return new self(SortKind::String, 'String');
    }

    public static function null(): self
    {
        return new self(SortKind::Null, 'Null');
    }

    public static function never(): self
    {
        return new self(SortKind::Never, 'Never');
    }

    public static function enum(EnumSort $enum): self
    {
        return new self(SortKind::Enum, $enum->smtName, enum: $enum);
    }

    public static function nullable(self $inner): self
    {
        return new self(SortKind::Nullable, 'Opt_' . $inner->smt, inner: $inner);
    }

    public static function record(RecordSort $record): self
    {
        return new self(SortKind::Record, $record->smtName, record: $record);
    }

    public static function list(self $element): self
    {
        return new self(SortKind::List, 'List_' . $element->smt, element: $element);
    }

    /**
     * @param list<string>|null $displayOrder
     */
    public static function shape(ShapeSort $shape, ?array $displayOrder = null): self
    {
        return new self(SortKind::Shape, $shape->smtName, shape: $shape, displayOrder: $displayOrder);
    }

    public static function emptyList(): self
    {
        return new self(SortKind::EmptyList, 'EmptyList');
    }

    public function isInt(): bool
    {
        return $this->kind === SortKind::Int;
    }

    public function isBool(): bool
    {
        return $this->kind === SortKind::Bool;
    }

    public function isFloat(): bool
    {
        return $this->kind === SortKind::Float;
    }

    public function isString(): bool
    {
        return $this->kind === SortKind::String;
    }

    public function isNullable(): bool
    {
        return $this->kind === SortKind::Nullable;
    }

    public function isNull(): bool
    {
        return $this->kind === SortKind::Null;
    }

    public function isNever(): bool
    {
        return $this->kind === SortKind::Never;
    }

    public function isShape(): bool
    {
        return $this->kind === SortKind::Shape;
    }

    public function isList(): bool
    {
        return $this->kind === SortKind::List;
    }

    public function isEmptyList(): bool
    {
        return $this->kind === SortKind::EmptyList;
    }

    /**
     * Whether a value of this sort is an array, or a nullable one.
     */
    public function isArrayLike(): bool
    {
        $sort = $this->isNullable() && $this->inner !== null ? $this->inner : $this;

        return $sort->isShape() || $sort->isList() || $sort->isEmptyList();
    }

    /**
     * Whether the `null` literal type is part of this sort, which no SMT datatype can hold.
     */
    public function containsNull(): bool
    {
        return match ($this->kind) {
            SortKind::Null => true,
            SortKind::Nullable => $this->inner?->containsNull() ?? false,
            SortKind::List => $this->element?->containsNull() ?? false,
            SortKind::Shape => array_reduce($this->shape->fields ?? [], static fn (bool $found, self $field): bool => $found || $field->containsNull(), false),
            default => false,
        };
    }

    public function isNumeric(): bool
    {
        return $this->kind === SortKind::Int || $this->kind === SortKind::Float;
    }

    public function equals(self $other): bool
    {
        return $this->kind === $other->kind && $this->smt === $other->smt;
    }

    public function describe(): string
    {
        return match ($this->kind) {
            SortKind::Int => 'int',
            SortKind::Bool => 'bool',
            SortKind::Float => 'float',
            SortKind::String => 'string',
            SortKind::Null => 'null',
            SortKind::Never => 'never',
            SortKind::Enum => $this->enum?->shortName() ?? $this->smt,
            SortKind::Record => $this->record?->shortName() ?? $this->smt,
            SortKind::Nullable => '?' . ($this->inner?->describe() ?? ''),
            SortKind::List => 'list<' . ($this->element?->describe() ?? '') . '>',
            SortKind::Shape => $this->shape?->describe($this->displayOrder) ?? $this->smt,
            SortKind::EmptyList => 'array{}',
        };
    }
}
