<?php

declare(strict_types=1);

namespace PhpstanContracts\Translate;

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
    /** @var array<string, array<Node>> */
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
    ) {
    }

    /**
     * @param array<Node> $arguments
     * @param array<string, Term> $env the caller's environment, for the arguments
     * @param Term|null $receiver the object of an instance call, null for a static call
     * @param bool $lateBound whether PHP picks the method from the runtime class (`$x->m()`, `static::m()`)
     */
    public function inline(
        ExpressionTranslator $caller,
        ?ClassReflection $callerScope,
        Expr $call,
        int $line,
        string $className,
        string $methodName,
        ?Term $receiver,
        bool $lateBound,
        array $arguments,
        array $env,
        Term $pc,
    ): Term {
        if (!$this->reflection->hasClass($className)) {
            throw new VerificationRejected(sprintf('Class %s cannot be resolved.', $className), $line);
        }
        $class = $this->reflection->getClass($className);
        if (!$class->hasNativeMethod($methodName)) {
            throw new VerificationRejected(sprintf('%s has no method %s().', $class->getDisplayName(), $methodName), $line);
        }

        $method = $class->getNativeMethod($methodName);
        $owner = $method->getDeclaringClass();
        $label = sprintf('%s::%s()', $owner->getDisplayName(), $method->getName());

        $this->checkCallable($class, $method, $owner, $callerScope, $label, $receiver !== null, $lateBound, $line);
        $node = $this->findBody($owner, $method, $label, $line);

        if (in_array($label, $this->stack, true)) {
            throw new VerificationRejected(sprintf('Recursive calls are not supported: %s is already being translated.', $label), $line);
        }

        $parameters = $this->parameterSorts($method, $node, $label, $line);
        $values = $this->bindArguments($caller, $call, $arguments, $parameters, $label, $line, $env, $pc);

        $this->stack[] = $label;
        try {
            return $this->translateBody($owner, $method, $node, $parameters, $values, $receiver, $label, $line, $pc);
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

    private function checkCallable(
        ClassReflection $class,
        ExtendedMethodReflection $method,
        ClassReflection $owner,
        ?ClassReflection $callerScope,
        string $label,
        bool $onAnObject,
        bool $lateBound,
        int $line,
    ): void {
        if (!$onAnObject && !$method->isStatic()) {
            throw new VerificationRejected(sprintf('%s is an instance method; call it on the object, such as $this->%s().', $label, $method->getName()), $line);
        }

        // `$x->m()` and `static::m()` run the method of the runtime class, which can be a subclass.
        $cannotBeOverridden = $class->isEnum()
            || $class->isFinalByKeyword()
            || $method->isFinalByKeyword()->yes()
            || $method->isPrivate();
        if ($lateBound && !$cannotBeOverridden) {
            throw new VerificationRejected(sprintf(
                '%s can be overridden: %s is not final, so a subclass can replace the method. Make the class or the method final, or the method private.',
                $label,
                $class->getDisplayName(),
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

        return $methodNode;
    }

    /**
     * @return array<string, Sort>
     */
    private function parameterSorts(ExtendedMethodReflection $method, ClassMethod $node, string $label, int $line): array
    {
        $sorts = [];
        foreach ($method->getOnlyVariant()->getParameters() as $index => $parameter) {
            $declared = $node->params[$index] ?? null;
            if ($declared === null || $declared->variadic || $declared->byRef) {
                throw new VerificationRejected(sprintf('%s has a variadic or by-reference parameter, which is not supported.', $label), $line);
            }
            $sorts[$parameter->getName()] = $this->types->map($parameter->getType(), $line)->sort;
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
            );
        }

        return $values;
    }

    /**
     * What PHP does to an argument before the callee sees it, in both typing modes:
     * nothing, wrapping into a nullable, or converting an int to a float.
     */
    private function coerce(Term $value, Sort $target, string $subject, int $line): Term
    {
        if ($value->sort->equals($target)) {
            return $value;
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
        string $label,
        int $line,
        Term $pc,
    ): Term {
        $resultSort = $this->types->map($method->getOnlyVariant()->getReturnType(), $line)->sort;

        $signature = array_values($parameters);
        $signature[] = $resultSort;
        $namespace = ($position = strrpos($owner->getName(), '\\')) === false ? null : substr($owner->getName(), 0, $position);
        $classes = new ClassNameResolver($this->reflection, $namespace, ClassNameResolver::classesOf($signature), $owner);

        $thisRefusal = $method->isStatic() ? 'There is no $this in a static method.' : 'Object state ($this) is not available here.';
        $body = new ExpressionTranslator($this->encoding, $this->types, $this->enums, $classes, $this->reflection, $this->initializers, false, $line, $thisRefusal, $this, $label);

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
            $entry[$name] = $this->coerce($body->translate($param->default, [], $pc), $parameters[$name], sprintf('The default value of $%s', $name), $line);
        }

        // The callee was proven assuming its preconditions; here they are checked.
        $view = $entry;
        foreach ($this->contracts->read($node)->requires as $clause) {
            $contract = new ExpressionTranslator($this->encoding, $this->types, $this->enums, $classes, $this->reflection, $this->initializers, true, $line, $thisRefusal, null, $label);
            $this->encoding->addObligation(new Obligation(
                ObligationKind::RequiresViolated,
                $line,
                $clause->source,
                $pc,
                $contract->translateBoolean($clause->expression, $view, $pc),
                $label,
            ));
            $view = $contract->narrow($clause->expression, true, $view);
        }

        $returns = new InlinedReturns();
        $statements = new StatementTranslator($body, $this->encoding, $resultSort, $returns);
        $final = $statements->execute($node->stmts ?? [], (new State($entry, $pc))->withView($view));

        if (!$final->reached->isFalse()) {
            $this->encoding->addObligation(new Obligation(ObligationKind::MissingReturn, $line, '', $final->reached, Smt::bool(false), $label));
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
        if (isset(self::$parsed[$key])) {
            return self::$parsed[$key];
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

        return self::$parsed[$key] = $traverser->traverse($statements);
    }
}
