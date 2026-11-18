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
            SortKind::Enum => $this->enum?->shortName() ?? $this->smt,
            SortKind::Record => $this->record?->shortName() ?? $this->smt,
            SortKind::Nullable => '?' . ($this->inner?->describe() ?? ''),
        };
    }
}
