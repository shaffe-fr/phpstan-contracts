<?php

declare(strict_types=1);

namespace PhpstanContracts\Smt;

/**
 * A PHP enum modelled as an SMT algebraic datatype with one nullary constructor per case.
 */
final readonly class EnumSort
{
    /** Whether PHP gives the enum from(), tryFrom() and ->value, even when they are not modelled. */
    public bool $backed;

    /**
     * @param array<string, int|string|null> $cases case name => backing value (null for pure enums)
     * @param 'int'|'string'|null $backingType null for a pure enum, and for a backed enum whose backing values are not modelled
     * @param string|null $unmodelledBacking why the backing values of a backed enum are not modelled
     */
    public function __construct(
        public string $className,
        public string $smtName,
        public array $cases,
        public ?string $backingType,
        public ?string $unmodelledBacking = null,
    ) {
        $this->backed = $backingType !== null || $unmodelledBacking !== null;
    }

    public function constructorOf(string $case): string
    {
        return 'C_' . substr($this->smtName, 2) . SmtName::SEPARATOR . SmtName::of($case);
    }

    public function valueFunction(): string
    {
        return $this->smtName . SmtName::SEPARATOR . 'value';
    }

    public function nameFunction(): string
    {
        return $this->smtName . SmtName::SEPARATOR . 'name';
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
