<?php

declare(strict_types=1);

namespace PhpstanContracts\Translate;

/**
 * The namespace and `use` imports in effect at a PHPDoc, which resolve its names as
 * PHPStan's NameScope::resolveStringName() and resolveConstantNames() do.
 */
final class NameContext
{
    /**
     * @param array<string, string> $uses lower-case alias => class
     * @param array<string, string> $constUses lower-case alias => constant
     */
    public function __construct(
        public readonly ?string $namespace,
        public array $uses = [],
        public array $constUses = [],
    ) {
    }

    public function resolveClass(string $name): string
    {
        if (str_starts_with($name, '\\')) {
            return ltrim($name, '\\');
        }
        $parts = explode('\\', $name);
        $first = strtolower($parts[0]);
        if (isset($this->uses[$first])) {
            array_shift($parts);
            return implode('\\', [$this->uses[$first], ...$parts]);
        }
        return $this->namespace === null ? $name : $this->namespace . '\\' . $name;
    }

    /**
     * @return list<string> the names PHPStan tries, in order
     */
    public function resolveConstant(string $name): array
    {
        if (str_starts_with($name, '\\')) {
            return [ltrim($name, '\\')];
        }
        $parts = explode('\\', $name);
        $first = strtolower($parts[0]);
        if (count($parts) > 1) {
            if (isset($this->uses[$first])) {
                array_shift($parts);
                return [implode('\\', [$this->uses[$first], ...$parts])];
            }
        } elseif (isset($this->constUses[$first])) {
            return [$this->constUses[$first]];
        }
        return $this->namespace === null ? [$name] : [$this->namespace . '\\' . $name, $name];
    }
}
