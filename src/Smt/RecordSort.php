<?php

declare(strict_types=1);

namespace PhpstanContracts\Smt;

/**
 * A readonly data class modelled as an SMT datatype with a single constructor
 * whose arguments are the fields, in declaration order.
 */
final readonly class RecordSort
{
    /**
     * @param array<string, Sort> $fields field name => sort
     * @param list<string>|null $constructorOrder constructor parameter names, or null when `new` is not supported
     */
    public function __construct(
        public string $className,
        public string $smtName,
        public array $fields,
        public ?array $constructorOrder,
        public ?string $constructionRefusal,
    ) {
    }

    public function constructorName(): string
    {
        return 'mk_' . $this->smtName;
    }

    public function accessor(string $field): string
    {
        return $this->smtName . '_' . preg_replace('/[^A-Za-z0-9_]/', '_', $field);
    }

    public function shortName(): string
    {
        $position = strrpos($this->className, '\\');

        return $position === false ? $this->className : substr($this->className, $position + 1);
    }
}
