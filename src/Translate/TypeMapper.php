<?php

declare(strict_types=1);

namespace PhpstanContracts\Translate;

use PhpstanContracts\Exception\VerificationRejected;
use PhpstanContracts\Smt\RecordSort;
use PhpstanContracts\Smt\SmtName;
use PhpstanContracts\Smt\ShapeSort;
use PhpstanContracts\Smt\Sort;
use PhpParser\Node;
use PhpParser\Node\Stmt\ClassLike;
use PhpParser\NodeFinder;
use PHPStan\Reflection\ClassReflection;
use PHPStan\Type\Constant\ConstantFloatType;
use PHPStan\Type\Constant\ConstantIntegerType;
use PHPStan\Type\IntegerRangeType;
use PHPStan\Type\Type;
use PHPStan\Type\TypeCombinator;
use PHPStan\Type\UnionType;
use PHPStan\Type\VerbosityLevel;

/**
 * Maps a PHPStan type, including PHPDoc ranges, to an SMT sort and its domain.
 *
 * Supported: int, bool, float, string, enums, readonly data classes, lists, array
 * shapes with string keys, and any of those made nullable.
 */
final class TypeMapper
{
    /** @var array<string, array{VarSpec, Dependencies}> class => its record, and what building it read */
    private array $records = [];

    /** @var list<string> */
    private array $building = [];

    private ?VerificationRejected $recursion = null;

    /** How many properties a message names before it counts the rest. */
    private const LISTED_PROPERTIES = 5;
    private const SUPPORTED_CLASSES = 'only enums, readonly data classes, lists, array shapes with string keys and scalars are';
    /** @var array<string, array{string|null, Dependencies}> class declaring a constructor => why its body cannot be modelled, null when it is empty, and what reading it read */
    private array $constructorBodies = [];

    public function __construct(
        private readonly EnumRegistry $enums,
        private readonly Lookups $lookups,
        private readonly SourceFiles $sources,
        private readonly PhpDocReads $phpDocs,
    ) {
    }

    /**
     * @param bool $input true when the type describes a value the caller supplies (a parameter or a
     *                    data class property): PHP never converts the values of an array, so a `float`
     *                    field of a shape may hold an int there.
     */
    public function map(Type $type, int $line, bool $input = false): VarSpec
    {
        // A class named in the type decides what the type is, even when the type is rejected.
        foreach ($type->getReferencedClasses() as $referenced) {
            $this->lookups->hasClass($referenced);
        }

        // typeOnly() would print an array shape as array<string, int>, without its keys.
        $description = $type->describe(TypeCombinator::removeNull($type)->isArray()->yes() ? VerbosityLevel::value() : VerbosityLevel::typeOnly());

        if (TypeCombinator::containsNull($type) && !$type->isNull()->yes()) {
            $inner = $this->map(TypeCombinator::removeNull($type), $line, $input);

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

        if ($type->isArray()->yes()) {
            return $this->mapArray($type, $description, $line, $input);
        }

        $classNames = $type->getObjectClassNames();
        if (count($classNames) === 1) {
            return $this->mapClass($classNames[0], $line);
        }

        throw $this->unsupported($description, $line);
    }

    private function unsupported(string $description, int $line): VerificationRejected
    {
        return new VerificationRejected(
            sprintf(
                'Type %s is not supported in verified methods (supported: int, bool, float, string, enums, readonly data classes, lists, array shapes with string keys, and nullable versions of these).',
                $description,
            ),
            $line,
        );
    }

    /**
     * An `array{a: T, b: U}` with required string keys is a shape; every other array type
     * is rejected with what to write instead.
     */
    private function mapArray(Type $type, string $description, int $line, bool $input): VarSpec
    {
        $constants = $type->getConstantArrays();
        if (count($constants) > 1) {
            throw $this->unsupported($description, $line);
        }
        if (count($constants) === 0) {
            if ($type->isList()->yes()) {
                return $this->mapList($type, $description, $line, $input);
            }

            throw new VerificationRejected(
                sprintf('Type %s is not supported in verified methods: an array with arbitrary keys is not modelled. Use list<T> for a sequence, or an array shape with string keys such as array{net: int, vat: int}.', $description),
                $line,
            );
        }

        $array = $constants[0];
        $keys = $array->getKeyTypes();
        if ($keys === []) {
            throw new VerificationRejected(
                sprintf('Type %s is not supported: declare list<T> for a list that may be empty.', $description),
                $line,
            );
        }
        $names = [];
        foreach ($keys as $key) {
            $strings = $key->getConstantStrings();
            if (count($strings) !== 1) {
                throw new VerificationRejected(
                    sprintf('Type %s is not supported: array shapes are only supported with string keys. Use array{amount: int, label: string} or a data class.', $description),
                    $line,
                );
            }
            $names[] = $strings[0]->getValue();
        }
        foreach ($array->getOptionalKeys() as $optional) {
            throw new VerificationRejected(
                sprintf('Type %s is not supported: the optional key %s may be missing, which is not modelled. Make the key required, with a nullable value if it can be absent.', $description, $names[$optional] ?? ''),
                $line,
            );
        }

        $specs = [];
        foreach ($array->getValueTypes() as $position => $valueType) {
            $spec = $this->map($valueType, $line, $input);
            if ($input && ($spec->sort->isFloat() || ($spec->sort->isNullable() && $spec->inner?->sort->isFloat() === true))) {
                throw new VerificationRejected(
                    sprintf('Type %s is not supported for an input: PHP does not convert the values of an array and PHPStan accepts an int for a float in an array shape, so the field %s may hold an int. Use int (for example cents) or a data class with a native float property.', $description, $names[$position]),
                    $line,
                );
            }
            $specs[$names[$position]] = $spec;
        }
        $declared = array_map(strval(...), array_keys($specs));
        uksort($specs, static fn (int|string $a, int|string $b): int => strcmp((string) $a, (string) $b));
        $shape = ShapeSort::of(array_map(static fn (VarSpec $spec): Sort => $spec->sort, $specs));

        return new VarSpec(Sort::shape($shape, $declared), fields: $specs, description: $description);
    }

    /**
     * `list<T>` and `non-empty-list<T>`: keys 0..n-1 in order, every value of one supported type.
     */
    private function mapList(Type $type, string $description, int $line, bool $input): VarSpec
    {
        $elementType = $type->getIterableValueType();
        try {
            $element = $this->map($elementType, $line, $input);
        } catch (VerificationRejected $unsupported) {
            if ($unsupported !== $this->recursion && TypeCombinator::removeNull($elementType) instanceof UnionType) {
                throw new VerificationRejected(
                    sprintf(
                        'Type %s is not supported in verified methods: its elements have type %s, and a list must hold a single supported type (int, bool, float, string, an enum, a readonly data class, an array shape, or a nullable version of these).',
                        $description,
                        $elementType->describe(VerbosityLevel::value()),
                    ),
                    $line,
                );
            }

            throw $unsupported;
        }

        $elementSort = $element->sort->isNullable() && $element->sort->inner !== null ? $element->sort->inner : $element->sort;
        if ($elementSort->isList()) {
            throw new VerificationRejected(
                sprintf('Type %s is not supported yet: a list of lists is not modelled. Use a list of data classes or of array shapes.', $description),
                $line,
            );
        }
        if ($input && $elementSort->isFloat()) {
            throw new VerificationRejected(
                sprintf('Type %s is not supported for an input: PHP does not convert the values of an array and PHPStan accepts an int for a float in a list, so an element may hold an int. Use int (for example cents) or a list of data classes with a native float property.', $description),
                $line,
            );
        }

        return new VarSpec(
            Sort::list($element->sort),
            nonEmpty: $type->isIterableAtLeastOnce()->yes(),
            description: $description,
            element: $element,
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
        if (!$this->lookups->hasClass($className)) {
            throw new VerificationRejected(sprintf('Class %s cannot be resolved.', $className), $line);
        }

        // Class names are case-insensitive: every spelling of a class is the same record.
        $class = $this->lookups->getClass($className);
        $className = $class->getName();

        if (isset($this->records[$className])) {
            [$record, $dependencies] = $this->records[$className];
            $this->lookups->replay($dependencies);

            return $record;
        }

        if (in_array($className, $this->building, true)) {
            throw $this->recursion = new VerificationRejected(sprintf('Class %s refers to itself; recursive types are not supported.', $className), $line);
        }

        $this->records[$className] = $this->lookups->capture(fn (): VarSpec => $this->buildRecord($class, $line));

        return $this->records[$className][0];
    }

    private function buildRecord(ClassReflection $class, int $line): VarSpec
    {
        $className = $class->getName();
        // The PHPDoc types of its properties can name constants and aliases of other classes.
        $this->phpDocs->record($class, $line, '__construct');

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
            // The public readonly properties that could not be modelled, with their type and the reason.
            $publicReadonly = [];
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
                $declared = $class->getNativeProperty($property->getName());
                $fieldType = $declared->getReadableType();
                // typeOnly() would print an array shape as array<string, int>, without its keys.
                $typeName = $fieldType->describe(TypeCombinator::removeNull($fieldType)->isArray()->yes() ? VerbosityLevel::value() : VerbosityLevel::typeOnly());
                $uninitialized = $this->uninitializedReason($class, $constructor, $property, $label);
                if ($uninitialized !== null) {
                    $unmodelled[$property->getName()] = $uninitialized;
                    $publicReadonly[$property->getName()] = sprintf('$%s (%s): %s', $property->getName(), $typeName, $uninitialized);
                    continue;
                }

                try {
                    $fieldSpec = $this->map($fieldType, $line, true);
                    if (self::holdsFloat($fieldSpec->sort) && !$this->convertsToFloat($declared->getNativeType())) {
                        $unmodelled[$property->getName()] = sprintf(
                            'Property %s cannot be read in a verified method: its PHPDoc type is %s but its native type is %s, so an int assigned to it stays an int. Declare float natively.',
                            $label,
                            $fieldType->describe(VerbosityLevel::typeOnly()),
                            $declared->getNativeType()->describe(VerbosityLevel::typeOnly()),
                        );
                        $publicReadonly[$property->getName()] = sprintf('$%s (%s): %s', $property->getName(), $typeName, $unmodelled[$property->getName()]);
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
                    $publicReadonly[$property->getName()] = sprintf('$%s (%s): %s', $property->getName(), $typeName, $unsupported->getMessage());
                }
            }
        } finally {
            array_pop($this->building);
        }

        if ($fieldSpecs === []) {
            throw new VerificationRejected($this->noModelledProperty($class, $unmodelled, $publicReadonly), $line);
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

        return new VarSpec(
            Sort::record($record),
            fields: $fieldSpecs,
            description: $class->getDisplayName(),
        );
    }

    /**
     * Why a class that has no property to model is not a supported type. What the user can act
     * on comes first: the public readonly properties whose type is not supported, each with its
     * type and reason, and only then the properties that are not public and readonly, which
     * come from a base class as often as not.
     *
     * @param array<string, string> $unmodelled
     * @param array<string, string> $publicReadonly
     */
    private function noModelledProperty(ClassReflection $class, array $unmodelled, array $publicReadonly): string
    {
        $name = $class->getDisplayName();
        $head = sprintf('Type %s is not supported: %s.', $name, self::SUPPORTED_CLASSES);

        if ($unmodelled === []) {
            return sprintf('%s %s has no properties to model.', $head, $name);
        }
        if ($publicReadonly === []) {
            return sprintf('%s %s', $head, reset($unmodelled));
        }

        // The sentence about what is supported is said once, at the head of the message.
        $listed = array_map(
            static fn (string $reason): string => rtrim(str_replace(' ' . self::SUPPORTED_CLASSES . '.', '', $reason), '.'),
            array_slice(array_values($publicReadonly), 0, self::LISTED_PROPERTIES),
        );
        $others = array_keys(array_diff_key($unmodelled, $publicReadonly));

        return sprintf(
            '%s None of the public readonly properties of %s can be modelled: %s%s%s',
            $head,
            $name,
            implode('; ', $listed),
            count($publicReadonly) > count($listed) ? sprintf('; and %d more', count($publicReadonly) - count($listed)) : '',
            $others === [] ? '' : sprintf('. Its properties that are not public and readonly are not modelled either: %s', implode(', ', array_map(static fn (string $other): string => '$' . $other, array_slice($others, 0, self::LISTED_PROPERTIES)))),
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
        $constructor = $this->lookups->getClass($className)->getNativeReflection()->getConstructor();
        $declaredIn = $constructor?->getFileName();
        if ($constructor === null || !is_string($declaredIn) || $file === null || realpath($declaredIn) !== realpath($file)) {
            return 'its constructor is declared in another file. Only classes whose constructor is in the same file can be built: PHPStan re-analyses a file when the signatures it uses change, not when a body in another file does, so the proof would not be redone';
        }

        $declaringClass = $constructor->getDeclaringClass()->getName();
        if (array_key_exists($declaringClass, $this->constructorBodies)) {
            [$refusal, $dependencies] = $this->constructorBodies[$declaringClass];
            $this->lookups->replay($dependencies);

            return $refusal;
        }

        $this->constructorBodies[$declaringClass] = $this->lookups->capture(fn (): ?string => $this->readConstructorBody($declaredIn, $declaringClass));

        return $this->constructorBodies[$declaringClass][0];
    }

    private function readConstructorBody(string $file, string $className): ?string
    {
        $statements = $this->sources->tryParse($file);
        if (is_string($statements)) {
            return $statements;
        }

        $class = (new NodeFinder())->findFirst(
            $statements,
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
