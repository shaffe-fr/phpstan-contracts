<?php

declare(strict_types=1);

namespace PhpstanContracts\Translate;

use PhpParser\Node;
use PhpParser\Node\Expr;
use PhpParser\Node\Expr\BinaryOp\Concat;
use PhpParser\Node\Expr\Cast;
use PhpParser\Node\Expr\ClassConstFetch;
use PhpParser\Node\Expr\ConstFetch;
use PhpParser\Node\Identifier;
use PhpParser\Node\Name;
use PhpParser\NodeFinder;
use PHPStan\BetterReflection\Reflection\Adapter\ReflectionEnum;
use PHPStan\BetterReflection\Reflection\Adapter\ReflectionEnumBackedCase;
use PHPStan\Reflection\AttributeReflection;
use PHPStan\Reflection\ClassConstantReflection;
use PHPStan\Reflection\ClassReflection;
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
 */
final class ConstantGuard
{
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

        return $this->constant($constant, $seen);
    }

    public function caseRefusal(ClassReflection $enum, string $case): ?string
    {
        $seen = [];

        return $this->enumCase($enum, $case, $seen);
    }

    /**
     * For the backing value of an enum case, whose deprecation is checked where the case is read.
     */
    public function initializerRefusal(Expr $initializer, ClassReflection $context): ?string
    {
        $seen = [];

        return $this->walk($initializer, $context, $seen);
    }

    /**
     * @param array<string, true> $seen
     */
    private function constant(ClassConstantReflection $constant, array &$seen): ?string
    {
        $owner = $constant->getDeclaringClass();
        $label = sprintf('%s::%s', $owner->getDisplayName(), $constant->getName());
        if (isset($seen[strtolower($label)])) {
            return null;
        }
        $seen[strtolower($label)] = true;

        if (self::hasDeprecatedAttribute($constant->getAttributes())) {
            return $this->deprecated('Constant ' . $label);
        }

        return $this->walk($constant->getValueExpr(), $owner, $seen);
    }

    /**
     * @param array<string, true> $seen
     */
    private function enumCase(ClassReflection $enum, string $case, array &$seen): ?string
    {
        $label = sprintf('%s::%s', $enum->getDisplayName(), $case);
        if (isset($seen[strtolower($label)])) {
            return null;
        }
        $seen[strtolower($label)] = true;

        if (self::hasDeprecatedAttribute($enum->getEnumCase($case)->getAttributes())) {
            return $this->deprecated('Enum case ' . $label);
        }

        $native = $enum->getNativeReflection();
        if (!$enum->isBackedEnum() || !$native instanceof ReflectionEnum) {
            return null;
        }
        $value = $native->getCase($case);

        return $value instanceof ReflectionEnumBackedCase
            ? $this->walk($value->getValueExpression(), $enum, $seen)
            : null;
    }

    /**
     * @param array<string, true> $seen
     */
    private function walk(Expr $initializer, ClassReflection $context, array &$seen): ?string
    {
        $nodes = (new NodeFinder())->find(
            $initializer,
            static fn (Node $node): bool => $node instanceof Concat || $node instanceof Cast\String_ || $node instanceof ClassConstFetch || $node instanceof ConstFetch,
        );

        foreach ($nodes as $node) {
            $refusal = match (true) {
                $node instanceof Concat => $this->floatToString([$node->left, $node->right], $context),
                $node instanceof Cast\String_ => $this->floatToString([$node->expr], $context),
                $node instanceof ClassConstFetch => $this->classConstant($node, $context, $seen),
                $node instanceof ConstFetch => $this->globalConstant($node),
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
    private function floatToString(array $operands, ClassReflection $context): ?string
    {
        foreach ($operands as $operand) {
            $type = $this->initializers->getType($operand, InitializerExprContext::fromClassReflection($context));
            if (!$type->isFloat()->no()) {
                return sprintf(
                    'An initializer in %s converts a float to a string, whose digits depend on the precision ini setting, which is not modelled.',
                    $context->getDisplayName(),
                );
            }
        }

        return null;
    }

    /**
     * @param array<string, true> $seen
     */
    private function classConstant(ClassConstFetch $fetch, ClassReflection $context, array &$seen): ?string
    {
        if (!$fetch->class instanceof Name || !$fetch->name instanceof Identifier) {
            return sprintf('An initializer in %s reads a class constant dynamically.', $context->getDisplayName());
        }
        $name = $fetch->name->toString();
        if (strtolower($name) === 'class') {
            return null;
        }

        $class = match (strtolower($fetch->class->toString())) {
            'self', 'static' => $context,
            'parent' => $context->getParentClass(),
            default => $this->reflection->hasClass($fetch->class->toString()) ? $this->reflection->getClass($fetch->class->toString()) : null,
        };
        if ($class === null) {
            return sprintf('An initializer in %s reads a constant of %s, which cannot be resolved.', $context->getDisplayName(), $fetch->class->toString());
        }

        if ($class->isEnum() && $class->hasEnumCase($name)) {
            return $this->enumCase($class, $name, $seen);
        }
        if (!$class->hasConstant($name)) {
            return sprintf('An initializer in %s reads %s::%s, which cannot be resolved.', $context->getDisplayName(), $class->getDisplayName(), $name);
        }

        return $this->constant($class->getConstant($name), $seen);
    }

    private function globalConstant(ConstFetch $fetch): ?string
    {
        if (in_array(strtolower($fetch->name->toString()), ['true', 'false', 'null'], true)) {
            return null;
        }
        if (!$this->reflection->hasConstant($fetch->name, null)) {
            return null;
        }

        $constant = $this->reflection->getConstant($fetch->name, null);
        if (self::hasDeprecatedAttribute($constant->getAttributes()) || $constant->isDeprecated()->yes()) {
            return $this->deprecated('Constant ' . $constant->getName());
        }

        return null;
    }

    private function deprecated(string $subject): string
    {
        return sprintf('%s is deprecated: reading it triggers a deprecation notice, which runs the error handler.', $subject);
    }
}
