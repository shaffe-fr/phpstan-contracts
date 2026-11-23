<?php

declare(strict_types=1);

namespace PhpstanContracts\Translate;

use PhpstanContracts\Exception\VerificationRejected;
use PhpstanContracts\Smt\Sort;
use PhpstanContracts\Smt\SortKind;
use PhpParser\Node\Name;
use PHPStan\Reflection\ClassReflection;
use PHPStan\Reflection\ReflectionProvider;

/**
 * Contract strings are parsed outside the file's name-resolution pass, so `use`
 * imports are unavailable. A short name resolves first against the types in the
 * method signature, then against the current namespace, then globally.
 *
 * `self` and `static` resolve to the class that declares the method, in the body
 * and in contracts alike. `static` is late bound, so it only means that class when
 * no subclass can exist.
 */
final class ClassNameResolver
{
    /**
     * @param array<string, string> $signatureClasses short name => fully qualified name
     */
    public function __construct(
        private readonly ReflectionProvider $reflectionProvider,
        private readonly ?string $namespace,
        private readonly array $signatureClasses,
        private readonly ?ClassReflection $declaringClass = null,
    ) {
    }

    public function resolve(Name $name, int $line): string
    {
        $special = $name->toLowerString();
        if ($special === 'static' && !$this->declaringClassIsFinal()) {
            throw new VerificationRejected(
                sprintf(
                    'static:: is late bound and %s is not final, so a subclass can change what it refers to; use self:: or make the class final.',
                    $this->declaringClass?->getDisplayName() ?? 'the class',
                ),
                $line,
            );
        }

        return $this->resolveStatically($name, $line);
    }

    /**
     * Resolves `static` to the declaring class whether or not it is final. Only for
     * callers that check themselves that late binding cannot change the meaning.
     */
    public function resolveStatically(Name $name, int $line): string
    {
        switch ($name->toLowerString()) {
            case 'self':
            case 'static':
                return $this->declaringClass?->getName() ?? throw new VerificationRejected(
                    sprintf('%s:: is only available inside a class.', $name->toString()),
                    $line,
                    'contracts.invalidContract',
                );
            case 'parent':
                throw new VerificationRejected('parent:: is not supported; write the name of the parent class.', $line);
        }

        if ($name->isFullyQualified()) {
            return $name->toString();
        }

        $written = $name->toString();
        if (isset($this->signatureClasses[$written])) {
            return $this->signatureClasses[$written];
        }

        $candidates = $this->namespace !== null && $this->namespace !== ''
            ? [$this->namespace . '\\' . $written, $written]
            : [$written];

        foreach ($candidates as $candidate) {
            if ($this->reflectionProvider->hasClass($candidate)) {
                return $this->reflectionProvider->getClass($candidate)->getName();
            }
        }

        throw new VerificationRejected(
            sprintf('Cannot resolve class %s; write its fully qualified name.', $written),
            $line,
            'contracts.invalidContract',
        );
    }

    /**
     * The namespace of the code, which an unqualified function name is looked up in first.
     */
    public function namespace(): ?string
    {
        return $this->namespace;
    }

    public function isLateStatic(Name $name): bool
    {
        return $name->toLowerString() === 'static';
    }

    public function declaringClass(): ?ClassReflection
    {
        return $this->declaringClass;
    }

    /**
     * Short names of the enums and data classes in a signature, so that contract
     * strings can refer to them without a `use` statement.
     *
     * @param list<Sort> $sorts
     * @return array<string, string> short name => fully qualified name
     */
    public static function classesOf(array $sorts): array
    {
        $classes = [];
        foreach ($sorts as $sort) {
            self::collect($sort, $classes);
        }

        return $classes;
    }

    /**
     * @param array<string, string> $classes
     */
    private static function collect(Sort $sort, array &$classes): void
    {
        switch ($sort->kind) {
            case SortKind::Enum:
                if ($sort->enum !== null) {
                    $classes[$sort->enum->shortName()] = $sort->enum->className;
                }
                break;
            case SortKind::Record:
                if ($sort->record !== null) {
                    $classes[$sort->record->shortName()] = $sort->record->className;
                    foreach ($sort->record->fields as $field) {
                        self::collect($field, $classes);
                    }
                }
                break;
            case SortKind::Nullable:
                if ($sort->inner !== null) {
                    self::collect($sort->inner, $classes);
                }
                break;
            case SortKind::List:
                if ($sort->element !== null) {
                    self::collect($sort->element, $classes);
                }
                break;
            case SortKind::Shape:
                foreach ($sort->shape->fields ?? [] as $field) {
                    self::collect($field, $classes);
                }
                break;
            default:
                break;
        }
    }

    /**
     * A `final` keyword, not a `@final` tag: only the keyword stops a subclass at runtime.
     * Enums cannot be extended.
     */
    public function declaringClassIsFinal(): bool
    {
        return $this->declaringClass !== null
            && ($this->declaringClass->isEnum() || $this->declaringClass->isFinalByKeyword());
    }
}
