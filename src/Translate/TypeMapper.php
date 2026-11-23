<?php

declare(strict_types=1);

namespace PhpstanContracts\Translate;

use PhpstanContracts\Exception\VerificationRejected;
use PhpstanContracts\Smt\RecordSort;
use PhpstanContracts\Smt\SmtName;
use PhpstanContracts\Smt\Sort;
use PhpParser\Error;
use PhpParser\Node;
use PhpParser\Node\Stmt\ClassLike;
use PhpParser\NodeFinder;
use PhpParser\NodeTraverser;
use PhpParser\NodeVisitor\NameResolver;
use PhpParser\ParserFactory;
use PHPStan\Reflection\ClassReflection;
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

    private ?VerificationRejected $recursion = null;

    /** @var array<string, string|null> class declaring a constructor => why its body cannot be modelled, null when it is empty */
    private array $constructorBodies = [];

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
            throw $this->recursion = new VerificationRejected(sprintf('Class %s refers to itself; recursive types are not supported.', $className), $line);
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
        $constructor = $native->getConstructor();
        $this->building[] = $className;

        // A public readonly property cannot change once the object exists, whatever the
        // other properties do, so it is modelled even when the class also holds mutable
        // state, like the properties a framework base class brings in. Reading any other
        // property is rejected, with the reason recorded here.
        try {
            $fieldSpecs = [];
            $unmodelled = [];
            foreach ($native->getProperties() as $property) {
                if ($property->isStatic()) {
                    continue;
                }
                $label = sprintf('%s::$%s', $class->getDisplayName(), $property->getName());
                if (!$property->isReadOnly() || !$property->isPublic()) {
                    $unmodelled[$property->getName()] = sprintf(
                        'Property %s must be public and readonly: only immutable data classes are supported.',
                        $label,
                    );
                    continue;
                }
                $uninitialized = $this->uninitializedReason($class, $constructor, $property, $label);
                if ($uninitialized !== null) {
                    $unmodelled[$property->getName()] = $uninitialized;
                    continue;
                }

                $declared = $class->getNativeProperty($property->getName());
                $fieldType = $declared->getReadableType();
                try {
                    $fieldSpec = $this->map($fieldType, $line);
                    if (self::holdsFloat($fieldSpec->sort) && !$this->convertsToFloat($declared->getNativeType())) {
                        $unmodelled[$property->getName()] = sprintf(
                            'Property %s cannot be read in a verified method: its PHPDoc type is %s but its native type is %s, so an int assigned to it stays an int. Declare float natively.',
                            $label,
                            $fieldType->describe(VerbosityLevel::typeOnly()),
                            $declared->getNativeType()->describe(VerbosityLevel::typeOnly()),
                        );
                        continue;
                    }
                    $fieldSpecs[$property->getName()] = $fieldSpec;
                } catch (VerificationRejected $unsupported) {
                    // Which class of a cycle is reached first depends on the analysis order,
                    // so a cycle rejects every class in it instead of hiding one field.
                    if ($unsupported === $this->recursion) {
                        throw $unsupported;
                    }
                    $unmodelled[$property->getName()] = sprintf(
                        'Property %s cannot be read in a verified method: %s',
                        $label,
                        $unsupported->getMessage(),
                    );
                }
            }
        } finally {
            array_pop($this->building);
        }

        if ($fieldSpecs === []) {
            throw new VerificationRejected(
                $unmodelled === [] ? sprintf('Class %s has no properties to model.', $className) : reset($unmodelled),
                $line,
            );
        }

        [$order, $refusal] = $this->constructorOrder($constructor, $fieldSpecs, $unmodelled);

        $record = new RecordSort(
            $className,
            'R_' . SmtName::of($class->getName()),
            array_map(static fn (VarSpec $spec): Sort => $spec->sort, $fieldSpecs),
            $order,
            $refusal,
            $unmodelled,
        );

        return $this->records[$className] = new VarSpec(
            Sort::record($record),
            fields: $fieldSpecs,
            description: $class->getDisplayName(),
        );
    }

    public static function holdsFloat(Sort $sort): bool
    {
        return $sort->isFloat() || ($sort->isNullable() && $sort->inner?->isFloat() === true);
    }

    /**
     * Whether PHP converts an int to a float for a parameter or property of this native type.
     */
    public function convertsToFloat(Type $native): bool
    {
        try {
            return self::holdsFloat($this->map($native, 0)->sort);
        } catch (VerificationRejected) {
            return false;
        }
    }

    /**
     * Reading a typed property that no one has set throws an Error. A property promoted by
     * the constructor is set before the constructor body runs, so it is set in every object
     * that the constructor built, provided that every object of the type is built by that
     * constructor: the class is final, or the constructor is, so no subclass constructor
     * can skip it. Objects created without their constructor, through reflection or
     * unserialize(), are outside the guarantee.
     */
    private function uninitializedReason(ClassReflection $class, ?\ReflectionMethod $constructor, \ReflectionProperty $property, string $label): ?string
    {
        if (!$property->isPromoted()) {
            return sprintf(
                'Property %s may be uninitialized: only the properties promoted by the constructor are known to be set in every object.',
                $label,
            );
        }
        if ($constructor === null || $constructor->getDeclaringClass()->getName() !== $property->getDeclaringClass()->getName()) {
            return sprintf(
                'Property %s may be uninitialized: it is promoted by the constructor of %s, which the constructor of %s does not have to call.',
                $label,
                $property->getDeclaringClass()->getName(),
                $class->getDisplayName(),
            );
        }
        if (!$class->isFinalByKeyword() && !$constructor->isFinal()) {
            return sprintf(
                'Property %s may be uninitialized: %s is not final and neither is its constructor, so a subclass constructor can skip the one that sets it. Make the class or the constructor final.',
                $label,
                $class->getDisplayName(),
            );
        }

        return null;
    }

    /**
     * `new X(...)` can be modelled when the constructor only promotes every property
     * and does nothing else: then an instance is exactly its constructor arguments.
     * This checks the parameters, which are part of the signature; the body is checked
     * by {@see constructorBodyRefusal()} for the file that builds the object.
     *
     * The properties left out of the model keep their default value, or stay uninitialized,
     * and are never read, so they do not change what the instance is.
     *
     * @param array<string, VarSpec> $fields
     * @param array<string, string> $unmodelled
     * @return array{list<string>|null, string|null}
     */
    private function constructorOrder(?\ReflectionMethod $constructor, array $fields, array $unmodelled): array
    {
        if ($constructor === null) {
            return [null, 'it has no constructor'];
        }

        $order = [];
        foreach ($constructor->getParameters() as $parameter) {
            if ($parameter->isPromoted() && isset($unmodelled[$parameter->getName()])) {
                return [null, sprintf('its constructor sets $%s, which is not modelled', $parameter->getName())];
            }
            if (!$parameter->isPromoted() || !isset($fields[$parameter->getName()])) {
                return [null, 'its constructor takes parameters that are not promoted properties'];
            }
            $order[] = $parameter->getName();
        }

        if (count($order) !== count($fields)) {
            return [null, 'its constructor does not set every property'];
        }

        return [$order, null];
    }

    /**
     * Why the body of the constructor of a data class cannot be relied on in `$file`, or
     * null when it is empty and declared in that file.
     *
     * Only that file: when a file changes, PHPStan re-analyses the files that depend on
     * it only if its exported nodes change (ResultCacheManager), and an exported method
     * keeps its signature, not its body (ExportedNodeResolver). A proof that read the
     * constructor body of another file would not be redone when that body changes.
     */
    public function constructorBodyRefusal(string $className, ?string $file): ?string
    {
        $constructor = $this->reflectionProvider->getClass($className)->getNativeReflection()->getConstructor();
        $declaredIn = $constructor?->getFileName();
        if ($constructor === null || !is_string($declaredIn) || $file === null || realpath($declaredIn) !== realpath($file)) {
            return 'its constructor is declared in another file. Only classes whose constructor is in the same file can be built: PHPStan re-analyses a file when the signatures it uses change, not when a body in another file does, so the proof would not be redone';
        }

        $declaringClass = $constructor->getDeclaringClass()->getName();
        if (!array_key_exists($declaringClass, $this->constructorBodies)) {
            $this->constructorBodies[$declaringClass] = $this->readConstructorBody($declaredIn, $declaringClass);
        }

        return $this->constructorBodies[$declaringClass];
    }

    private function readConstructorBody(string $file, string $className): ?string
    {
        $code = @file_get_contents($file);
        if ($code === false) {
            return sprintf('%s cannot be read', $file);
        }

        try {
            $statements = (new ParserFactory())->createForNewestSupportedVersion()->parse($code) ?? [];
        } catch (Error) {
            return sprintf('%s cannot be parsed', $file);
        }

        $class = (new NodeFinder())->findFirst(
            (new NodeTraverser(new NameResolver()))->traverse($statements),
            static fn (Node $node): bool => $node instanceof ClassLike && $node->namespacedName?->toString() === $className,
        );
        $constructor = $class instanceof ClassLike ? $class->getMethod('__construct') : null;

        return match (true) {
            $constructor === null => 'the body of its constructor cannot be found; constructors that come from a trait are not supported',
            $constructor->stmts !== [] => 'its constructor contains logic, which is not verified',
            default => null,
        };
    }
}
