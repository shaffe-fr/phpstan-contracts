<?php

declare(strict_types=1);

namespace PhpstanContracts\Translate;

use PhpstanContracts\Smt\EnumSort;
use PHPStan\Reflection\ReflectionProvider;

final class EnumRegistry
{
    /** @var array<string, EnumSort|null> */
    private array $cache = [];

    public function __construct(private readonly ReflectionProvider $reflectionProvider)
    {
    }

    public function find(string $className): ?EnumSort
    {
        if (array_key_exists($className, $this->cache)) {
            return $this->cache[$className];
        }

        return $this->cache[$className] = $this->load($className);
    }

    private function load(string $className): ?EnumSort
    {
        if (!$this->reflectionProvider->hasClass($className)) {
            return null;
        }

        $class = $this->reflectionProvider->getClass($className);
        if (!$class->isEnum()) {
            return null;
        }

        $cases = [];
        $complete = $class->isBackedEnum();
        foreach ($class->getEnumCases() as $case) {
            $backing = $case->getBackingValueType();
            $constants = $backing?->getConstantScalarValues() ?? [];
            $value = count($constants) === 1 && (is_int($constants[0]) || is_string($constants[0])) ? $constants[0] : null;
            $cases[$case->getName()] = $value;
            $complete = $complete && $value !== null;
        }

        if ($cases === []) {
            return null;
        }

        $backingType = null;
        if ($complete) {
            $backingType = is_int(reset($cases)) ? 'int' : 'string';
        }

        return new EnumSort(
            $class->getName(),
            'E_' . preg_replace('/[^A-Za-z0-9_]/', '_', $class->getName()),
            $cases,
            $backingType,
        );
    }
}
