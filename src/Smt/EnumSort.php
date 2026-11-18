<?php

declare(strict_types=1);

namespace PhpstanContracts\Smt;

/**
 * A PHP enum modelled as an SMT algebraic datatype with one nullary constructor per case.
 */
final readonly class EnumSort
{
    /**
     * @param array<string, int|string|null> $cases case name => backing value (null for pure enums)
     * @param 'int'|'string'|null $backingType
     */
    public function __construct(
        public string $className,
        public string $smtName,
        public array $cases,
        public ?string $backingType,
    ) {
    }

    public function constructorOf(string $case): string
    {
        return 'C_' . substr($this->smtName, 2) . '_' . $case;
    }

    public function valueFunction(): string
    {
        return $this->smtName . '_value';
    }

    public function nameFunction(): string
    {
        return $this->smtName . '_name';
    }

    public function displayConstructor(string $constructor): string
    {
        foreach (array_keys($this->cases) as $case) {
            if ($this->constructorOf((string) $case) === $constructor) {
                return $this->shortName() . '::' . $case;
            }
        }

        return $constructor;
    }

    public function shortName(): string
    {
        $position = strrpos($this->className, '\\');

        return $position === false ? $this->className : substr($this->className, $position + 1);
    }
}
