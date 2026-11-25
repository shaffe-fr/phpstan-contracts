<?php

declare(strict_types=1);

namespace PhpstanContracts\Translate;

use PhpstanContracts\Attribute\Requires;
use PhpstanContracts\Attribute\Throws;
use PhpstanContracts\Contract\ContractReader;
use PhpstanContracts\Exception\VerificationRejected;
use PhpstanContracts\Smt\Smt;
use PhpstanContracts\Smt\Sort;
use PhpstanContracts\Smt\Term;
use PhpParser\Error;
use PhpParser\Node;
use PhpParser\Node\Arg;
use PhpParser\Node\Expr;
use PhpParser\Node\Stmt\ClassLike;
use PhpParser\Node\Stmt\ClassMethod;
use PhpParser\NodeFinder;
use PhpParser\NodeTraverser;
use PhpParser\NodeVisitor\NameResolver;
use PhpParser\ParserFactory;
use PHPStan\Reflection\ClassReflection;
use PHPStan\Reflection\ExtendedMethodReflection;
use PHPStan\Reflection\InitializerExprTypeResolver;
use PHPStan\Reflection\ReflectionProvider;
use PHPStan\Type\TypeCombinator;
use PHPStan\Type\VerbosityLevel;

/**
 * A call to a method declared in the same file is translated by translating the
 * body of the callee, with its parameters bound to the arguments and `$this` to
 * the receiver. The proof then sees exactly what the callee computes, with its
 * own safety checks under the path that reaches the call, and its `#[Requires]`
 * become obligations of the caller.
 *
 * Only the same file, because PHPStan re-analyses a file when it changes, and
 * the files that depend on it only when a signature changes: a proof that read
 * the body of a method in another file would not be redone when that body changes.
 */
final class MethodInliner
{
    /** The file parsed last, by path and modification time: the calls of a file are translated together. */
    private static ?string $parsedKey = null;

    /** @var array<Node> */
    private static array $parsed = [];

    /**
     * @param list<string> $stack the methods being translated, outermost first
     */
    public function __construct(
        private readonly Encoding $encoding,
        private readonly TypeMapper $types,
        private readonly EnumRegistry $enums,
        private readonly ReflectionProvider $reflection,
        private readonly InitializerExprTypeResolver $initializers,
        private readonly ContractReader $contracts,
        private readonly string $file,
        private array $stack,
        private readonly int $loopUnroll = 10,
        private readonly int $maxLoopUnroll = 100,
    ) {
    }

    /**
     * @param array<Node> $arguments
     * @param array<string, Term> $env the caller's environment, for the arguments
     * @param Term|null $receiver the object of an instance call, null for a static call
     * @param string|null $statelessThis the class of `$this` in `$this->m()` when it has no state, in which case there is no receiver term
     * @param bool $lateStatic whether the call is `static::m()`, which PHP looks up in the called class
     */
    public function inline(
        ExpressionTranslator $caller,
        ?ClassReflection $callerScope,
        Expr $call,
        int $line,
        string $className,
        string $methodName,
        ?Term $receiver,
        bool $lateStatic,
        array $arguments,
        array $env,
        Term $pc,
        ?string $statelessThis = null,
    ): Term {
        $onAnObject = $receiver !== null || $statelessThis !== null;
        if (!$this->reflection->hasClass($className)) {
            throw new VerificationRejected(sprintf('Class %s cannot be resolved.', $className), $line);
        }
        $class = $this->reflection->getClass($className);
        if (!$class->hasNativeMethod($methodName)) {
            throw new VerificationRejected(sprintf('%s has no method %s().', $class->getDisplayName(), $methodName), $line);
        }

        $method = $class->getNativeMethod($methodName);

        // `$x->m()` from a class S runs the private S::m() when $x is an instance of a
        // subclass of S, even if the subclass declares a public m(): PHP flags a method
        // that redeclares a private one, and then picks the private method of the
        // calling scope (zend_get_parent_private_method). Static calls do not do this.
        if (
            $onAnObject
            && $callerScope !== null
            && $class->getName() !== $callerScope->getName()
            && $class->isSubclassOfClass($callerScope)
            && $callerScope->hasNativeMethod($methodName)
        ) {
            $scoped = $callerScope->getNativeMethod($methodName);
            if ($scoped->isPrivate() && $scoped->getDeclaringClass()->getName() === $callerScope->getName()) {
                $class = $callerScope;
                $method = $scoped;
            }
        }

        $owner = $method->getDeclaringClass();
        $label = sprintf('%s::%s()', $owner->getDisplayName(), $method->getName());

        $this->checkCallable($class, $method, $owner, $callerScope, $label, $onAnObject, $lateStatic, $line);
        $node = $this->findBody($owner, $method, $label, $line);

        if (in_array($label, $this->stack, true)) {
            throw new VerificationRejected(sprintf('Recursive calls are not supported: %s is already being translated.', $label), $line);
        }

        $parameters = $this->parameterSorts($method, $label, $line);
        $values = $this->bindArguments($caller, $call, $arguments, $parameters, $label, $line, $env, $pc);

        $this->stack[] = $label;
        try {
            return $this->translateBody($owner, $method, $node, $parameters, $values, $receiver, $statelessThis, $label, $line, $pc);
        } catch (VerificationRejected $rejected) {
            throw new VerificationRejected(
                sprintf('The call to %s cannot be verified: %s', $label, $rejected->getMessage()),
                $line,
                $rejected->identifier,
            );
        } finally {
            array_pop($this->stack);
        }
    }

    /**
     * The arguments of a static factory that builds a thrown exception: translated in the
     * caller's context, so that their own obligations apply, and checked against the
     * parameters with the coercions of an inlined call. The body of the factory is not
     * read, and so nothing else of it is known.
     *
     * @param array<Node> $arguments
     * @param array<string, Term> $env
     */
    public function bindFactoryArguments(
        ExpressionTranslator $caller,
        Expr $call,
        ExtendedMethodReflection $method,
        array $arguments,
        array $env,
        Term $pc,
        int $line,
    ): void {
        $label = sprintf('%s::%s()', $method->getDeclaringClass()->getDisplayName(), $method->getName());
        $native = $method->getDeclaringClass()->getNativeReflection()->getMethod($method->getName());

        // A contract of the factory would be neither checked at this call nor assumed.
        foreach ([Requires::class, Throws::class, 'Deprecated'] as $attribute) {
            if ($native->getAttributes($attribute) !== []) {
                throw new VerificationRejected(sprintf(
                    '%s carries #[%s]: the body of an exception factory is not inspected, so a precondition would not be checked at this call, and a factory that throws or triggers a deprecation does not return the exception that the proof assumes. Build the exception with `new`, or remove the attribute.',
                    $label,
                    basename(str_replace("\\", "/", $attribute)),
                ), $line);
            }
        }

        $parameters = $this->parameterSorts($method, $label, $line);
        $values = $this->bindArguments($caller, $call, $arguments, $parameters, $label, $line, $env, $pc);
        foreach ($native->getParameters() as $parameter) {
            if (!isset($values[$parameter->getName()]) && !$parameter->isOptional()) {
                throw new VerificationRejected(sprintf('Every parameter of %s without a default value needs an argument.', $label), $line);
            }
        }
    }

    private function checkCallable(
        ClassReflection $class,
        ExtendedMethodReflection $method,
        ClassReflection $owner,
        ?ClassReflection $callerScope,
        string $label,
        bool $onAnObject,
        bool $lateStatic,
        int $line,
    ): void {
        if (!$onAnObject && !$method->isStatic()) {
            throw new VerificationRejected(sprintf('%s is an instance method; call it on the object, such as $this->%s().', $label, $method->getName()), $line);
        }

        // `$x->m()` and `static::m()` run the method of the runtime class, which can be a subclass.
        // A private method cannot be final: a subclass can always declare one of the same name.
        $finalClass = $class->isEnum() || $class->isFinalByKeyword();
        $finalMethod = $method->isFinalByKeyword()->yes() && !$method->isPrivate();

        if ($onAnObject && !$finalClass && !$finalMethod && !$method->isPrivate()) {
            // A private method is the one of the calling scope, see inline().
            throw new VerificationRejected(sprintf(
                '%s can be overridden: %s is not final, so a subclass can replace the method. Make the class or the method final, or the method private.',
                $label,
                $class->getDisplayName(),
            ), $line);
        }
        if ($lateStatic && !$finalClass && !$finalMethod) {
            // `static::m()` looks the name up in the called class, whose own m() wins even over a private one.
            throw new VerificationRejected(sprintf(
                '%s can be overridden: %s is not final, so a subclass can declare its own %s(), which static:: calls instead%s. Use self::, or make the class or the method final.',
                $label,
                $class->getDisplayName(),
                $method->getName(),
                $method->isPrivate() ? ', even though this one is private' : '',
            ), $line);
        }

        // PHPStan reports an inaccessible method too, but a proof must not rest on a call that throws.
        $accessible = $method->isPublic()
            || ($callerScope !== null && ($method->isPrivate()
                ? $callerScope->getName() === $owner->getName()
                : $callerScope->getName() === $owner->getName() || $callerScope->isSubclassOfClass($owner) || $owner->isSubclassOfClass($callerScope)));
        if (!$accessible) {
            throw new VerificationRejected(sprintf('%s is not accessible here.', $label), $line);
        }
    }

    private function findBody(ClassReflection $owner, ExtendedMethodReflection $method, string $label, int $line): ClassMethod
    {
        $native = $owner->getNativeReflection()->getMethod($method->getName());
        $file = $native->getFileName();
        if ($file === false || realpath($file) !== realpath($this->file)) {
            throw new VerificationRejected(sprintf(
                '%s is declared in another file. Only methods of the same file can be called: PHPStan re-analyses a file when the signatures it uses change, not when a body in another file does, so the proof would not be redone.',
                $label,
            ), $line);
        }

        $finder = new NodeFinder();
        $classNode = $finder->findFirst(
            $this->parse($file, $line),
            static fn (Node $node): bool => $node instanceof ClassLike && $node->namespacedName?->toString() === $owner->getName(),
        );
        $methodNode = $classNode instanceof ClassLike ? $classNode->getMethod($method->getName()) : null;

        if ($methodNode === null) {
            throw new VerificationRejected(sprintf('The body of %s cannot be found; methods that come from a trait are not supported.', $label), $line);
        }
        if ($methodNode->stmts === null) {
            throw new VerificationRejected(sprintf('%s has no body.', $label), $line);
        }
        if (StatementTranslator::isGenerator($methodNode->stmts)) {
            throw new VerificationRejected(sprintf('%s contains a yield, so calling it returns a Generator.', $label), $line);
        }
        foreach ($methodNode->attrGroups as $group) {
            foreach ($group->attrs as $attribute) {
                if (strcasecmp($attribute->name->toString(), 'Deprecated') === 0) {
                    throw new VerificationRejected(sprintf(
                        '%s is #[\Deprecated]: calling it triggers E_USER_DEPRECATED, which runs the error handler.',
                        $label,
                    ), $line);
                }
            }
        }

        return $methodNode;
    }

    /**
     * @return array<string, Sort>
     */
    private function parameterSorts(ExtendedMethodReflection $method, string $label, int $line): array
    {
        $natives = $method->getDeclaringClass()->getNativeReflection()->getMethod($method->getName())->getParameters();
        $sorts = [];
        foreach ($method->getOnlyVariant()->getParameters() as $index => $parameter) {
            $declared = $natives[$index] ?? null;
            if ($declared === null || $declared->isVariadic() || $declared->isPassedByReference()) {
                throw new VerificationRejected(sprintf('%s has a variadic or by-reference parameter, which is not supported.', $label), $line);
            }

            // PHP converts an argument by the native type only: an int stays an int for a
            // parameter typed `float` in PHPDoc alone, or natively `int|float`.
            if (!$declared->hasType()) {
                throw new VerificationRejected(sprintf(
                    'Parameter $%s of %s has no native type, so PHP passes its argument as it is; declare the type in the signature.',
                    $parameter->getName(),
                    $label,
                ), $line);
            }
            $sort = $this->types->map($parameter->getType(), $line)->sort;
            try {
                $native = $this->types->map($parameter->getNativeType(), $line)->sort;
            } catch (VerificationRejected) {
                $native = null;
            }
            // PHP never converts an array argument, in either typing mode: the native type
            // `array` (or `?array`) holds exactly what an array shape in PHPDoc describes.
            $nativeType = $parameter->getNativeType();
            $nativeNullable = TypeCombinator::containsNull($nativeType);
            $inner = $sort->isNullable() && $sort->inner !== null ? $sort->inner : $sort;
            if ($native === null && ($inner->isShape() || $inner->isList()) && $nativeNullable === $sort->isNullable() && TypeCombinator::removeNull($nativeType)->isArray()->yes()) {
                $native = $sort;
            }
            if ($native === null || !$native->equals($sort)) {
                throw new VerificationRejected(sprintf(
                    'Parameter $%s of %s has the native type %s, which is what PHP converts its argument to, but the PHPDoc type %s; make them agree.',
                    $parameter->getName(),
                    $label,
                    $parameter->getNativeType()->describe(VerbosityLevel::typeOnly()),
                    $parameter->getType()->describe(VerbosityLevel::typeOnly()),
                ), $line);
            }
            $sorts[$parameter->getName()] = $sort;
        }

        return $sorts;
    }

    /**
     * The arguments, in the caller's context, by parameter name. A missing argument
     * is left out and takes its default value in the callee.
     *
     * @param array<Node> $arguments
     * @param array<string, Sort> $parameters
     * @param array<string, Term> $env
     * @return array<string, Term>
     */
    private function bindArguments(ExpressionTranslator $caller, Expr $call, array $arguments, array $parameters, string $label, int $line, array $env, Term $pc): array
    {
        $names = array_keys($parameters);
        $values = [];

        foreach ($arguments as $position => $argument) {
            if (!$argument instanceof Arg || $argument->unpack || $argument->byRef) {
                throw new VerificationRejected('Argument unpacking and first-class callables are not supported.', $line);
            }

            $name = $argument->name?->toString() ?? $names[$position] ?? null;
            if ($name === null || !isset($parameters[$name]) || isset($values[$name])) {
                throw new VerificationRejected(sprintf('Invalid arguments for %s.', $label), $line);
            }

            $values[$name] = $this->coerce(
                $caller->translate($argument->value, $env, $pc),
                $parameters[$name],
                sprintf('Argument $%s of %s', $name, $label),
                $line,
                $caller,
                $argument->value,
            );
        }

        return $values;
    }

    /**
     * What PHP does to an argument before the callee sees it, in both typing modes:
     * nothing, wrapping into a nullable, or converting an int to a float for a
     * parameter whose native type is `float` or `?float`, which parameterSorts() ensures.
     */
    private function coerce(Term $value, Sort $target, string $subject, int $line, ExpressionTranslator $translator, Node $at): Term
    {
        if ($value->sort->equals($target)) {
            return $value;
        }
        $this->encoding->registerSort($target);
        if (($value->sort->isShape() || $value->sort->isEmptyList()) && $translator->canCoerce($value->sort, $target)) {
            return $translator->coerce($at, $value, $target);
        }
        if ($target->isNullable() && $value->sort->isNull()) {
            return Smt::none($target);
        }

        $inner = $target->isNullable() && $target->inner !== null ? $target->inner : $target;
        if ($inner->isFloat() && $value->sort->isInt()) {
            $value = $this->encoding->define('f', Smt::intToFloat($value));
        }
        if ($value->sort->equals($inner)) {
            return $target->isNullable() ? Smt::some($value) : $value;
        }

        throw new VerificationRejected(sprintf('%s is %s, but the parameter is %s.', $subject, $value->sort->describe(), $target->describe()), $line);
    }

    /**
     * @param array<string, Sort> $parameters
     * @param array<string, Term> $values
     */
    private function translateBody(
        ClassReflection $owner,
        ExtendedMethodReflection $method,
        ClassMethod $node,
        array $parameters,
        array $values,
        ?Term $receiver,
        ?string $statelessThis,
        string $label,
        int $line,
        Term $pc,
    ): Term {
        $resultSort = $this->types->map($method->getOnlyVariant()->getReturnType(), $line)->sort;

        $signature = array_values($parameters);
        $signature[] = $resultSort;
        $namespace = ($position = strrpos($owner->getName(), '\\')) === false ? null : substr($owner->getName(), 0, $position);
        $classes = new ClassNameResolver($this->reflection, $namespace, ClassNameResolver::classesOf($signature), $owner);

        $statelessThis = $method->isStatic() ? null : $statelessThis;
        $thisRefusal = match (true) {
            $method->isStatic() => 'There is no $this in a static method.',
            $statelessThis !== null => MethodEncoder::statelessRefusal($statelessThis),
            default => 'Object state ($this) is not available here.',
        };
        // The calls that lead here from the verified method, outermost first, this one last.
        $calls = array_slice($this->stack, 1);
        $body = new ExpressionTranslator($this->encoding, $this->types, $this->enums, $classes, $this->reflection, $this->initializers, false, $line, $thisRefusal, $this, $calls, file: $this->file, statelessThis: $statelessThis);

        $entry = [];
        if ($receiver !== null && !$method->isStatic()) {
            $entry['this'] = $receiver;
        }
        foreach ($node->params as $index => $param) {
            $name = array_keys($parameters)[$index];
            if (isset($values[$name])) {
                $entry[$name] = $this->encoding->define($name, $values[$name]);
                continue;
            }
            if ($param->default === null) {
                throw new VerificationRejected(sprintf('Every parameter of %s without a default value needs an argument.', $label), $line);
            }
            $entry[$name] = $this->coerce($body->translate($param->default, [], $pc), $parameters[$name], sprintf('The default value of $%s', $name), $line, $body, $param->default);
        }

        // The callee was proven assuming its preconditions; here they are checked.
        $view = $entry;
        foreach ($this->contracts->read($node)->requires as $clause) {
            $contract = new ExpressionTranslator($this->encoding, $this->types, $this->enums, $classes, $this->reflection, $this->initializers, true, $line, $thisRefusal, null, $calls, file: $this->file);
            $this->encoding->addObligation(new Obligation(
                ObligationKind::RequiresViolated,
                $line,
                $clause->source,
                $pc,
                $contract->translateBoolean($clause->expression, $view, $pc),
                $calls,
            ));
            $view = $contract->narrow($clause->expression, true, $view);
        }

        $returns = new InlinedReturns();
        $loopBounds = LoopBounds::of($this->contracts->read($node)->requires, $body, $this->loopUnroll, $this->maxLoopUnroll);
        $statements = new StatementTranslator($body, $this->encoding, $resultSort, $loopBounds, $entry, $calls, $returns);
        $final = $statements->execute($node->stmts ?? [], (new State($entry, $pc))->withView($view));

        if (!$final->reached->isFalse()) {
            $this->encoding->addObligation(new Obligation(ObligationKind::MissingReturn, $line, '', $final->reached, Smt::bool(false), $calls));
        }
        if ($returns->isEmpty()) {
            throw new VerificationRejected(sprintf('%s never returns.', $label), $line);
        }

        return $this->encoding->define('call', $returns->value($resultSort));
    }

    /**
     * @return array<Node>
     */
    private function parse(string $file, int $line): array
    {
        $key = $file . ':' . (string) @filemtime($file);
        if (self::$parsedKey === $key) {
            return self::$parsed;
        }

        $code = @file_get_contents($file);
        if ($code === false) {
            throw new VerificationRejected(sprintf('Cannot read %s.', $file), $line);
        }

        try {
            $statements = (new ParserFactory())->createForNewestSupportedVersion()->parse($code) ?? [];
        } catch (Error) {
            throw new VerificationRejected(sprintf('Cannot parse %s.', $file), $line);
        }

        $traverser = new NodeTraverser(new NameResolver());
        self::$parsed = $traverser->traverse($statements);
        self::$parsedKey = $key;

        return self::$parsed;
    }
}
