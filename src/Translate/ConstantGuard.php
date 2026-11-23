<?php

declare(strict_types=1);

namespace PhpstanContracts\Translate;

use PhpParser\Error;
use PhpParser\Node;
use PhpParser\Node\Arg;
use PhpParser\Node\Expr;
use PhpParser\Node\Expr\BinaryOp\Concat;
use PhpParser\Node\Expr\Cast;
use PhpParser\Node\Expr\ClassConstFetch;
use PhpParser\Node\Expr\ConstFetch;
use PhpParser\Node\Expr\FuncCall;
use PhpParser\Node\Identifier;
use PhpParser\Node\Name;
use PhpParser\Node\Scalar\String_;
use PhpParser\Node\Stmt;
use PhpParser\NodeFinder;
use PhpParser\NodeTraverser;
use PhpParser\NodeVisitor\NameResolver;
use PhpParser\ParserFactory;
use PHPStan\BetterReflection\Reflection\Adapter\ReflectionEnum;
use PHPStan\BetterReflection\Reflection\Adapter\ReflectionEnumBackedCase;
use PHPStan\Reflection\AttributeReflection;
use PHPStan\Reflection\ClassConstantReflection;
use PHPStan\Reflection\ClassReflection;
use PHPStan\Reflection\ConstantReflection;
use PHPStan\Reflection\InitializerExprContext;
use PHPStan\Reflection\InitializerExprTypeResolver;
use PHPStan\Reflection\ReflectionProvider;

/**
 * Why a class constant or an enum case cannot be read as the value PHPStan folds it to.
 *
 * Two things in the initializer make the folded value wrong for some runs: reading a
 * `#[\Deprecated]` constant, case or global constant triggers a deprecation, which runs
 * the error handler (and throws under the handlers of Laravel or PHPUnit), and converting
 * a float to a string depends on the `precision` ini setting, which PHPStan folds with
 * its own value. Constants referenced by the initializer are checked the same way.
 *
 * A global constant that is not built in is evaluated where it is defined, not where it
 * is read, so only the float-to-string conversion is looked for in its initializer.
 */
final class ConstantGuard
{
    /** @var array<string, Expr|string> global constant => its initializer with every name resolved, or why it cannot be checked */
    private array $globalInitializers = [];

    /** @var array<string, array<Node>|string> file => its statements with names resolved, or why it cannot be read */
    private array $files = [];

    public function __construct(
        private readonly ReflectionProvider $reflection,
        private readonly InitializerExprTypeResolver $initializers,
    ) {
    }

    /**
     * By attribute name rather than isDeprecated(), which also counts the `@deprecated`
     * PHPDoc tag, and so that the verdict does not depend on the PHP version running
     * PHPStan: the attribute has an effect from PHP 8.4 on.
     *
     * @param list<AttributeReflection> $attributes
     */
    public static function hasDeprecatedAttribute(array $attributes): bool
    {
        foreach ($attributes as $attribute) {
            if (strcasecmp(ltrim($attribute->getName(), '\\'), 'Deprecated') === 0) {
                return true;
            }
        }

        return false;
    }

    public function constantRefusal(ClassConstantReflection $constant): ?string
    {
        $seen = [];

        return $this->constant($constant, $seen, true);
    }

    public function caseRefusal(ClassReflection $enum, string $case): ?string
    {
        $seen = [];

        return $this->enumCase($enum, $case, $seen, true);
    }

    /**
     * For the backing value of an enum case, whose deprecation is checked where the case is read.
     */
    public function initializerRefusal(Expr $initializer, ClassReflection $context): ?string
    {
        $seen = [];

        return $this->walk($initializer, InitializerExprContext::fromClassReflection($context), $context, 'An initializer in ' . $context->getDisplayName(), $seen, true);
    }

    /**
     * @param array<string, true> $seen
     * @param bool $deprecations whether a deprecated constant read here triggers its notice where the folded value is read
     */
    private function constant(ClassConstantReflection $constant, array &$seen, bool $deprecations): ?string
    {
        $owner = $constant->getDeclaringClass();
        $label = sprintf('%s::%s', $owner->getDisplayName(), $constant->getName());
        if (self::visited($seen, 'class:' . strtolower($label), $deprecations)) {
            return null;
        }

        if ($deprecations && self::hasDeprecatedAttribute($constant->getAttributes())) {
            return $this->deprecated('Constant ' . $label);
        }

        return $this->walk($constant->getValueExpr(), InitializerExprContext::fromClassReflection($owner), $owner, 'An initializer in ' . $owner->getDisplayName(), $seen, $deprecations);
    }

    /**
     * @param array<string, true> $seen
     */
    private function enumCase(ClassReflection $enum, string $case, array &$seen, bool $deprecations): ?string
    {
        $label = sprintf('%s::%s', $enum->getDisplayName(), $case);
        if (self::visited($seen, 'class:' . strtolower($label), $deprecations)) {
            return null;
        }

        if ($deprecations && self::hasDeprecatedAttribute($enum->getEnumCase($case)->getAttributes())) {
            return $this->deprecated('Enum case ' . $label);
        }

        $native = $enum->getNativeReflection();
        if (!$enum->isBackedEnum() || !$native instanceof ReflectionEnum) {
            return null;
        }
        $value = $native->getCase($case);

        return $value instanceof ReflectionEnumBackedCase
            ? $this->walk($value->getValueExpression(), InitializerExprContext::fromClassReflection($enum), $enum, 'An initializer in ' . $enum->getDisplayName(), $seen, $deprecations)
            : null;
    }

    /**
     * A constant can be reached twice, once where its deprecation matters and once where it does not.
     *
     * @param array<string, true> $seen
     */
    private static function visited(array &$seen, string $key, bool $deprecations): bool
    {
        $key = ($deprecations ? 'd:' : 'p:') . $key;
        if (isset($seen[$key])) {
            return true;
        }
        $seen[$key] = true;

        return false;
    }

    /**
     * @param ClassReflection|null $class the class whose constant or case this initializer is, for self:: and parent::
     * @param string $where how a refusal names this initializer
     * @param array<string, true> $seen
     */
    private function walk(Expr $initializer, InitializerExprContext $types, ?ClassReflection $class, string $where, array &$seen, bool $deprecations): ?string
    {
        $nodes = (new NodeFinder())->find(
            $initializer,
            static fn (Node $node): bool => $node instanceof Concat || $node instanceof Cast\String_ || $node instanceof ClassConstFetch || $node instanceof ConstFetch,
        );

        foreach ($nodes as $node) {
            $refusal = match (true) {
                $node instanceof Concat => $this->floatToString([$node->left, $node->right], $types, $where),
                $node instanceof Cast\String_ => $this->floatToString([$node->expr], $types, $where),
                $node instanceof ClassConstFetch => $this->classConstant($node, $class, $where, $seen, $deprecations),
                $node instanceof ConstFetch => $this->globalConstant($node, $types, $where, $seen, $deprecations),
                default => null,
            };
            if ($refusal !== null) {
                return $refusal;
            }
        }

        return null;
    }

    /**
     * @param list<Expr> $operands
     */
    private function floatToString(array $operands, InitializerExprContext $types, string $where): ?string
    {
        foreach ($operands as $operand) {
            if (!$this->initializers->getType($operand, $types)->isFloat()->no()) {
                return sprintf(
                    '%s converts a float to a string, whose digits depend on the precision ini setting, which is not modelled.',
                    $where,
                );
            }
        }

        return null;
    }

    /**
     * @param array<string, true> $seen
     */
    private function classConstant(ClassConstFetch $fetch, ?ClassReflection $context, string $where, array &$seen, bool $deprecations): ?string
    {
        if (!$fetch->class instanceof Name || !$fetch->name instanceof Identifier) {
            return sprintf('%s reads a class constant dynamically.', $where);
        }
        $name = $fetch->name->toString();
        if (strtolower($name) === 'class') {
            return null;
        }

        $class = match (strtolower($fetch->class->toString())) {
            'self', 'static' => $context,
            'parent' => $context?->getParentClass(),
            default => $this->reflection->hasClass($fetch->class->toString()) ? $this->reflection->getClass($fetch->class->toString()) : null,
        };
        if ($class === null) {
            return sprintf('%s reads a constant of %s, which cannot be resolved.', $where, $fetch->class->toString());
        }

        if ($class->isEnum() && $class->hasEnumCase($name)) {
            return $this->enumCase($class, $name, $seen, $deprecations);
        }
        if (!$class->hasConstant($name)) {
            return sprintf('%s reads %s::%s, which cannot be resolved.', $where, $class->getDisplayName(), $name);
        }

        return $this->constant($class->getConstant($name), $seen, $deprecations);
    }

    /**
     * @param array<string, true> $seen
     */
    private function globalConstant(ConstFetch $fetch, InitializerExprContext $types, string $where, array &$seen, bool $deprecations): ?string
    {
        if (in_array(strtolower($fetch->name->toString()), ['true', 'false', 'null'], true)) {
            return null;
        }
        // Resolved as PHPStan resolves it when it folds the initializer, namespace fallback included.
        if (!$this->reflection->hasConstant($fetch->name, $types)) {
            return sprintf('%s reads the constant %s, which cannot be resolved.', $where, $fetch->name->toString());
        }

        $constant = $this->reflection->getConstant($fetch->name, $types);
        if (self::visited($seen, 'global:' . $constant->getName(), $deprecations)) {
            return null;
        }
        if ($deprecations && (self::hasDeprecatedAttribute($constant->getAttributes()) || $constant->isDeprecated()->yes())) {
            return $this->deprecated('Constant ' . $constant->getName());
        }
        if ($constant->isBuiltin()->yes()) {
            return null;
        }

        $initializer = $this->globalInitializer($constant);
        if (is_string($initializer)) {
            return $initializer;
        }

        // Every name in the initializer is fully qualified by globalInitializer(), so no namespace is needed.
        return $this->walk($initializer, InitializerExprContext::createEmpty(), null, 'The initializer of constant ' . $constant->getName(), $seen, false);
    }

    /**
     * The value expression of a user global constant, read from the file that defines it:
     * a `const` statement or a `define()` call at the top level, found exactly once.
     * PHPStan re-analyses the files that read the constant when that expression changes,
     * since it is part of the exported nodes of the file (ExportedNodeResolver).
     */
    private function globalInitializer(ConstantReflection $constant): Expr|string
    {
        $name = $constant->getName();
        if (array_key_exists($name, $this->globalInitializers)) {
            return $this->globalInitializers[$name];
        }

        $file = $constant->getFileName();
        $statements = $file === null ? 'no file defines it' : $this->statements($file);
        if (is_string($statements)) {
            return $this->globalInitializers[$name] = $this->unchecked($name, $statements);
        }

        $found = [];
        foreach ($statements as $statement) {
            $inner = $statement instanceof Stmt\Namespace_ ? $statement->stmts : [$statement];
            foreach ($inner as $node) {
                if ($node instanceof Stmt\Const_) {
                    foreach ($node->consts as $const) {
                        if (self::sameConstant(($const->namespacedName ?? $const->name)->toString(), $name)) {
                            $found[] = $const->value;
                        }
                    }
                }
                $define = $node instanceof Stmt\Expression ? self::defined($node->expr) : null;
                if ($define !== null && self::sameConstant($define[0], $name)) {
                    $found[] = $define[1];
                }
            }
        }
        if (count($found) !== 1) {
            return $this->globalInitializers[$name] = $this->unchecked($name, sprintf('it is not defined exactly once at the top level of %s', $file));
        }

        foreach ((new NodeFinder())->findInstanceOf($found[0], ConstFetch::class) as $fetch) {
            $fetch->name = $this->fullyQualified($fetch->name);
        }

        return $this->globalInitializers[$name] = $found[0];
    }

    /**
     * @return array{string, Expr}|null
     */
    private static function defined(Expr $expr): ?array
    {
        if (!$expr instanceof FuncCall || !$expr->name instanceof Name || strtolower($expr->name->getLast()) !== 'define') {
            return null;
        }
        $args = $expr->args;
        if (count($args) < 2 || !$args[0] instanceof Arg || !$args[1] instanceof Arg || !$args[0]->value instanceof String_) {
            return null;
        }

        return [ltrim($args[0]->value->value, '\\'), $args[1]->value];
    }

    /**
     * The namespace of a constant name is case-insensitive, the rest is not.
     */
    private static function sameConstant(string $a, string $b): bool
    {
        $a = ltrim($a, '\\');
        $b = ltrim($b, '\\');
        $split = static function (string $name): array {
            $position = strrpos($name, '\\');

            return $position === false ? ['', $name] : [strtolower(substr($name, 0, $position)), substr($name, $position + 1)];
        };

        return $split($a) === $split($b);
    }

    /**
     * An unqualified constant in a namespace reads the namespaced constant when there is one, and the global one otherwise.
     */
    private function fullyQualified(Name $name): Name\FullyQualified
    {
        $namespaced = $name->getAttribute('namespacedName');
        if (!$name->isFullyQualified() && $namespaced instanceof Name && $this->reflection->hasConstant(new Name\FullyQualified($namespaced->toString()), null)) {
            return new Name\FullyQualified($namespaced->toString());
        }

        return new Name\FullyQualified($name->toString());
    }

    /**
     * @return array<Node>|string
     */
    private function statements(string $file): array|string
    {
        if (array_key_exists($file, $this->files)) {
            return $this->files[$file];
        }

        $code = @file_get_contents($file);
        if ($code === false) {
            return $this->files[$file] = sprintf('%s cannot be read', $file);
        }
        try {
            $statements = (new ParserFactory())->createForNewestSupportedVersion()->parse($code) ?? [];
        } catch (Error) {
            return $this->files[$file] = sprintf('%s cannot be parsed', $file);
        }

        return $this->files[$file] = (new NodeTraverser(new NameResolver()))->traverse($statements);
    }

    private function unchecked(string $constant, string $reason): string
    {
        return sprintf('The initializer of constant %s cannot be checked for a float converted to a string: %s.', $constant, $reason);
    }

    private function deprecated(string $subject): string
    {
        return sprintf('%s is deprecated: reading it triggers a deprecation notice, which runs the error handler.', $subject);
    }
}
