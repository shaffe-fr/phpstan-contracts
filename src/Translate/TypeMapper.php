<?php

declare(strict_types=1);

namespace PhpstanContracts\Translate;

use PhpstanContracts\Exception\VerificationRejected;
use PhpstanContracts\Smt\RecordSort;
use PhpstanContracts\Smt\Sort;
use PhpParser\Error;
use PhpParser\Node\Stmt\Class_;
use PhpParser\Node\Stmt\ClassMethod;
use PhpParser\NodeFinder;
use PhpParser\ParserFactory;
use PHPStan\Reflection\ReflectionProvider;
use PHPStan\Type\Constant\ConstantFloatType;
use PHPStan\Type\Constant\ConstantIntegerType;
use PHPStan\Type\IntegerRangeType;
use PHPStan\Type\Type;
use PHPStan\Type\TypeCombinator;
use PHPStan\Type\VerbosityLevel;

/**
 * Maps a PHPStan type, including PHPDoc ranges, to an SMT sort and its domain.
 *
 * Supported: int, bool, float, string, enums, readonly data classes, and any of
 * those made nullable.
 */
final class TypeMapper
{
    /** @var array<string, VarSpec> */
    private array $records = [];

    /** @var list<string> */
    private array $building = [];

    public function __construct(
        private readonly EnumRegistry $enums,
        private readonly ReflectionProvider $reflectionProvider,
    ) {
    }

    public function map(Type $type, int $line): VarSpec
    {
        $description = $type->describe(VerbosityLevel::typeOnly());

        if (TypeCombinator::containsNull($type) && !$type->isNull()->yes()) {
            $inner = $this->map(TypeCombinator::removeNull($type), $line);

            return new VarSpec(Sort::nullable($inner->sort), inner: $inner, description: $description);
        }

        if ($type instanceof IntegerRangeType) {
            return new VarSpec(Sort::int(), $type->getMin(), $type->getMax(), description: $description);
        }

        if ($type instanceof ConstantIntegerType) {
            return new VarSpec(Sort::int(), $type->getValue(), $type->getValue(), description: $description);
        }

        if ($type->isInteger()->yes()) {
            return new VarSpec(Sort::int(), description: $description);
        }

        if ($type->isBoolean()->yes()) {
            return new VarSpec(Sort::bool(), description: $description);
        }

        if ($type->isFloat()->yes() || $type instanceof ConstantFloatType) {
            return new VarSpec(Sort::float(), description: $description);
        }

        if ($type->isString()->yes()) {
            return new VarSpec(Sort::string(), nonEmpty: $type->isNonEmptyString()->yes(), description: $description);
        }

        $classNames = $type->getObjectClassNames();
        if (count($classNames) === 1) {
            return $this->mapClass($classNames[0], $line);
        }

        throw new VerificationRejected(
            sprintf(
                'Type %s is not supported in verified methods (supported: int, bool, float, string, enums, readonly data classes, and nullable versions of these).',
                $description,
            ),
            $line,
        );
    }

    public function mapClass(string $className, int $line): VarSpec
    {
        $enum = $this->enums->find($className);
        if ($enum !== null) {
            return new VarSpec(Sort::enum($enum), description: $enum->shortName());
        }

        return $this->mapRecord($className, $line);
    }

    private function mapRecord(string $className, int $line): VarSpec
    {
        if (isset($this->records[$className])) {
            return $this->records[$className];
        }

        if (in_array($className, $this->building, true)) {
            throw new VerificationRejected(sprintf('Class %s refers to itself; recursive types are not supported.', $className), $line);
        }

        if (!$this->reflectionProvider->hasClass($className)) {
            throw new VerificationRejected(sprintf('Class %s cannot be resolved.', $className), $line);
        }

        $class = $this->reflectionProvider->getClass($className);
        if ($class->isInterface() || $class->isAbstract() || $class->isAnonymous() || $class->isEnum()) {
            throw new VerificationRejected(
                sprintf('Type %s is not supported: only concrete readonly data classes are.', $className),
                $line,
            );
        }

        $native = $class->getNativeReflection();
        $this->building[] = $className;

        try {
            $fieldSpecs = [];
            foreach ($native->getProperties() as $property) {
                if ($property->isStatic()) {
                    continue;
                }
                if (!$property->isReadOnly() || !$property->isPublic()) {
                    throw new VerificationRejected(sprintf(
                        'Property %s::$%s must be public and readonly: only immutable data classes are supported.',
                        $class->getDisplayName(),
                        $property->getName(),
                    ), $line);
                }

                $fieldType = $class->getNativeProperty($property->getName())->getReadableType();
                $fieldSpecs[$property->getName()] = $this->map($fieldType, $line);
            }
        } finally {
            array_pop($this->building);
        }

        if ($fieldSpecs === []) {
            throw new VerificationRejected(sprintf('Class %s has no properties to model.', $className), $line);
        }

        [$order, $refusal] = $this->constructorOrder($native->getFileName() ?: null, $className, $native->getConstructor(), $fieldSpecs);

        $record = new RecordSort(
            $className,
            'R_' . preg_replace('/[^A-Za-z0-9_]/', '_', $className),
            array_map(static fn (VarSpec $spec): Sort => $spec->sort, $fieldSpecs),
            $order,
            $refusal,
        );

        return $this->records[$className] = new VarSpec(
            Sort::record($record),
            fields: $fieldSpecs,
            description: $class->getDisplayName(),
        );
    }

    /**
     * `new X(...)` can be modelled when the constructor only promotes every property
     * and does nothing else: then an instance is exactly its constructor arguments.
     *
     * @param array<string, VarSpec> $fields
     * @return array{list<string>|null, string|null}
     */
    private function constructorOrder(?string $file, string $className, ?\ReflectionMethod $constructor, array $fields): array
    {
        if ($constructor === null) {
            return [null, 'it has no constructor'];
        }

        $order = [];
        foreach ($constructor->getParameters() as $parameter) {
            if (!$parameter->isPromoted() || !isset($fields[$parameter->getName()])) {
                return [null, 'its constructor takes parameters that are not promoted properties'];
            }
            $order[] = $parameter->getName();
        }

        if (count($order) !== count($fields)) {
            return [null, 'its constructor does not set every property'];
        }

        if ($file === null || !$this->hasEmptyConstructorBody($file, $className)) {
            return [null, 'its constructor contains logic, which is not verified'];
        }

        return [$order, null];
    }

    private function hasEmptyConstructorBody(string $file, string $className): bool
    {
        $code = @file_get_contents($file);
        if ($code === false) {
            return false;
        }

        try {
            $statements = (new ParserFactory())->createForNewestSupportedVersion()->parse($code);
        } catch (Error) {
            return false;
        }

        $shortName = ($position = strrpos($className, '\\')) === false ? $className : substr($className, $position + 1);
        $finder = new NodeFinder();
        $class = $finder->findFirst(
            $statements ?? [],
            static fn ($node): bool => $node instanceof Class_ && $node->name?->toString() === $shortName,
        );
        if (!$class instanceof Class_) {
            return false;
        }

        $constructor = $finder->findFirst(
            $class->stmts,
            static fn ($node): bool => $node instanceof ClassMethod && $node->name->toLowerString() === '__construct',
        );

        return $constructor instanceof ClassMethod && $constructor->stmts === [];
    }
}
