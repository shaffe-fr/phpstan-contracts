<?php

declare(strict_types=1);

namespace PhpstanContracts\Translate;

use PhpstanContracts\Smt\EnumSort;
use PhpstanContracts\Smt\SmtName;
use PHPStan\BetterReflection\Reflection\Adapter\ReflectionEnumBackedCase;
use PHPStan\Reflection\ReflectionProvider;

final class EnumRegistry
{
    /** @var array<string, EnumSort|null> */
    private array $cache = [];

    public function __construct(
        private readonly ReflectionProvider $reflectionProvider,
        private readonly ConstantGuard $guard,
    ) {
    }

    public function guard(): ConstantGuard
    {
        return $this->guard;
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
        $unmodelled = null;
        $native = $class->getNativeReflection();
        foreach ($class->getEnumCases() as $case) {
            $backing = $case->getBackingValueType();
            $constants = $backing?->getConstantScalarValues() ?? [];
            $value = count($constants) === 1 && (is_int($constants[0]) || is_string($constants[0])) ? $constants[0] : null;
            $cases[$case->getName()] = $value;

            if (!$class->isBackedEnum() || $unmodelled !== null) {
                continue;
            }
            if ($value === null) {
                $unmodelled = sprintf(
                    'The backing value of %s::%s cannot be folded to a literal; write it as a literal.',
                    $class->getDisplayName(),
                    $case->getName(),
                );
                continue;
            }
            $initializer = $native->getCase($case->getName());
            if ($initializer instanceof ReflectionEnumBackedCase) {
                $unmodelled = $this->guard->initializerRefusal($initializer->getValueExpression(), $class);
            }
        }

        if ($cases === []) {
            return null;
        }

        $backingType = null;
        if ($class->isBackedEnum() && $unmodelled === null) {
            $backingType = is_int(reset($cases)) ? 'int' : 'string';
        }

        return new EnumSort(
            $class->getName(),
            'E_' . SmtName::of($class->getName()),
            $cases,
            $backingType,
            $unmodelled,
        );
    }
}
