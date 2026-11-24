<?php

declare(strict_types=1);

namespace PhpstanContracts\Translate;

use PhpstanContracts\Contract\ContractReader;
use PhpstanContracts\Contract\MethodContract;
use PhpstanContracts\Exception\VerificationRejected;
use PhpstanContracts\Smt\Smt;
use PhpstanContracts\Smt\Sort;
use PhpstanContracts\Smt\Term;
use PhpParser\Node;
use PhpParser\Node\Expr\Variable;
use PhpParser\Node\Stmt\ClassMethod;
use PhpParser\NodeFinder;
use PHPStan\Analyser\Scope;
use PHPStan\Node\InClassMethodNode;
use PHPStan\Reflection\InitializerExprTypeResolver;
use PHPStan\Reflection\ReflectionProvider;
use PHPStan\Type\VerbosityLevel;

/**
 * Turns one annotated method into an {@see Encoding}: parameters become
 * constants bounded by their PHPStan types, the body becomes SSA definitions,
 * and the contract clauses become assumptions and goals.
 */
final class MethodEncoder
{
    public function __construct(
        private readonly EnumRegistry $enums,
        private readonly TypeMapper $types,
        private readonly DomainBuilder $domains,
        private readonly ReflectionProvider $reflectionProvider,
        private readonly InitializerExprTypeResolver $initializers,
        private readonly ContractReader $contracts,
        private readonly int $loopUnroll = 10,
        private readonly int $maxLoopUnroll = 100,
    ) {
    }

    public function encode(InClassMethodNode $node, Scope $scope, MethodContract $contract): Encoding
    {
        $method = $node->getOriginalNode();
        $encoding = new Encoding();

        if (StatementTranslator::isGenerator($method->stmts ?? [])) {
            throw new VerificationRejected(StatementTranslator::GENERATOR_REFUSAL, $method->getStartLine());
        }

        /** @var array<string, Term> $entry */
        $entry = [];
        /** @var list<Sort> $signature */
        $signature = [];

        $declaringClass = $scope->getClassReflection();
        $thisRefusal = $method->isStatic()
            ? 'There is no $this in a static method.'
            : 'Object state ($this) is not part of the verified subset yet.';

        // `$this` is an implicit first parameter: its modelled properties are public readonly
        // and cannot change during the call, and reading any other property is rejected. It
        // is declared only when used, so that the counterexamples of other instance methods
        // stay as they are.
        if (!$method->isStatic() && $declaringClass !== null && $this->usesThis($method, $contract)) {
            try {
                $thisSpec = $this->types->mapClass($declaringClass->getName(), $method->getStartLine());
            } catch (VerificationRejected $notData) {
                $thisSpec = null;
                $thisRefusal = sprintf(
                    'Object state ($this) is only part of the verified subset in enums and readonly data classes. %s',
                    $notData->getMessage(),
                );
            }

            if ($thisSpec !== null) {
                $term = $encoding->declareInput('this', $thisSpec->sort);
                $encoding->assert($this->domains->constraint($thisSpec, $term, true));
                $signature[] = $thisSpec->sort;
                $entry['this'] = $term;
            }
        }

        foreach ($method->params as $param) {
            if (!$param->var instanceof Variable || !is_string($param->var->name)) {
                throw new VerificationRejected('Unsupported parameter.', $param->getStartLine());
            }
            if ($param->variadic || $param->byRef) {
                throw new VerificationRejected('Variadic and by-reference parameters are not supported.', $param->getStartLine());
            }

            $name = $param->var->name;
            if ($name === 'result') {
                throw new VerificationRejected('The parameter name $result is reserved for #[Ensures].', $param->getStartLine(), 'contracts.invalidContract');
            }

            $spec = $this->types->map($scope->getVariableType($name), $param->getStartLine(), true);
            $this->rejectPhpDocFloat($node, $name, $spec->sort, $param->getStartLine());
            $term = $encoding->declareInput($name, $spec->sort);
            $encoding->assert($this->domains->constraint($spec, $term, true));
            $signature[] = $spec->sort;

            $entry[$name] = $term;
        }

        $returnLine = ($method->returnType ?? $method)->getStartLine();
        $returnType = $node->getMethodReflection()->getOnlyVariant()->getReturnType();
        $resultSpec = $this->types->map($returnType, $returnLine);
        $signature[] = $resultSpec->sort;
        $result = $encoding->declareResult($resultSpec->sort);

        $classes = new ClassNameResolver($this->reflectionProvider, $scope->getNamespace(), ClassNameResolver::classesOf($signature), $declaringClass);

        // A precondition that checks for null tells the clauses after it, the body and the
        // postconditions that the value is not null, just like an `if` would.
        $view = $entry;
        foreach ($contract->requires as $clause) {
            $contractExpressions = $this->translator($encoding, $classes, true, $clause->line, $thisRefusal, $scope->getFile());
            $encoding->assert($contractExpressions->translateBoolean($clause->expression, $view, Smt::bool(true)));
            $view = $contractExpressions->narrow($clause->expression, true, $view);
        }

        $encoding->endAssumptions();

        // The conditions of `#[Throws]` are about the inputs on entry, like a precondition.
        $declaredThrows = [];
        foreach ($contract->throws as $throws) {
            if (!$this->reflectionProvider->hasClass($throws->class)) {
                throw new VerificationRejected(sprintf('Cannot resolve class %s in #[Throws]; write its fully qualified name.', $throws->class), $throws->line, 'contracts.invalidContract');
            }
            $thrown = $this->reflectionProvider->getClass($throws->class);
            if (!$thrown->implementsInterface(\Throwable::class) && $thrown->getName() !== \Throwable::class) {
                throw new VerificationRejected(sprintf('%s is not a Throwable class.', $thrown->getDisplayName()), $throws->line, 'contracts.invalidContract');
            }
            $when = $throws->when === null
                ? Smt::bool(true)
                : $this->translator($encoding, $classes, true, $throws->line, $thisRefusal, $scope->getFile())->translateBoolean($throws->when->expression, $view, Smt::bool(true));
            $declaredThrows[] = [$thrown->getName(), $encoding->define('throws', $when), $throws];
        }
        $policy = new ThrowPolicy(array_map(fn (array $declared): array => [
            $declared[0],
            $declared[2]->when === null ? null : $declared[1],
            $this->describeThrows($declared[0], $declared[2]->when?->source),
        ], $declaredThrows));

        $methodReflection = $node->getMethodReflection();
        $inliner = new MethodInliner(
            $encoding,
            $this->types,
            $this->enums,
            $this->reflectionProvider,
            $this->initializers,
            $this->contracts,
            $scope->getFile(),
            [sprintf('%s::%s()', $methodReflection->getDeclaringClass()->getDisplayName(), $methodReflection->getName())],
            $this->loopUnroll,
            $this->maxLoopUnroll,
        );
        $body = new ExpressionTranslator($encoding, $this->types, $this->enums, $classes, $this->reflectionProvider, $this->initializers, false, null, $thisRefusal, $inliner, [], $policy, $scope->getFile());
        $loopBounds = LoopBounds::of($contract->requires, $body, $this->loopUnroll, $this->maxLoopUnroll);
        $statements = new StatementTranslator($body, $encoding, $resultSpec->sort, $loopBounds, $entry);
        $final = $statements->execute($method->stmts ?? [], (new State($entry, Smt::bool(true)))->withView($view));

        if (!$final->reached->isFalse()) {
            $encoding->addObligation(new Obligation(
                ObligationKind::MissingReturn,
                $method->getEndLine(),
                '',
                $final->reached,
                Smt::bool(false),
            ));
        }

        $returnTypeGoal = $this->domains->constraint($resultSpec, $result, false);
        $returnCondition = $encoding->anyReturn();
        if (!$returnTypeGoal->isTrue() && !$returnCondition->isFalse()) {
            $encoding->returnTypeCheck = new Obligation(
                ObligationKind::ReturnType,
                $returnLine,
                $resultSpec->description,
                $returnCondition,
                $returnTypeGoal,
            );
        }

        // A postcondition is about the paths that return. Without a throw, every path does.
        $postconditionEnv = [...$view, 'result' => $result];
        foreach ($contract->ensures as $clause) {
            $contractExpressions = $this->translator($encoding, $classes, true, $clause->line, $thisRefusal, $scope->getFile());
            $term = $contractExpressions->translateBoolean($clause->expression, $postconditionEnv, $policy->anyThrow() ? $returnCondition : Smt::bool(true));
            $encoding->ensures[] = new EnsuresCheck(
                $clause->source,
                $clause->line,
                $policy->anyThrow() ? Smt::implies($returnCondition, $term) : $term,
            );
        }

        // `when` holds, so the method must throw: a return there breaks the declaration.
        foreach ($declaredThrows as [$class, $when, $throws]) {
            if ($throws->when !== null && !$returnCondition->isFalse()) {
                $encoding->throwsChecks[] = new ThrowsCheck(
                    $this->displayName($class),
                    $throws->when->source,
                    $throws->line,
                    Smt::all($returnCondition, $when),
                );
            }
        }

        return $encoding;
    }

    /**
     * PHP converts an int argument to float by the native type only: for a parameter typed
     * `float` in PHPDoc alone, which PHPStan lets callers pass an int to, the int stays an int.
     */
    private function rejectPhpDocFloat(InClassMethodNode $node, string $name, Sort $sort, int $line): void
    {
        if (!TypeMapper::holdsFloat($sort)) {
            return;
        }

        foreach ($node->getMethodReflection()->getOnlyVariant()->getParameters() as $parameter) {
            if ($parameter->getName() !== $name) {
                continue;
            }
            $native = $parameter->getNativeType();
            if (!$this->types->convertsToFloat($native)) {
                throw new VerificationRejected(sprintf(
                    'Parameter $%s has the PHPDoc type %s but the native type %s, which is what PHP converts its argument to: an int argument stays an int. Declare float in the signature.',
                    $name,
                    $parameter->getType()->describe(VerbosityLevel::typeOnly()),
                    $native->describe(VerbosityLevel::typeOnly()),
                ), $line);
            }
        }
    }

    private function describeThrows(string $class, ?string $when): string
    {
        return $when === null
            ? sprintf('#[Throws(%s::class)]', $this->displayName($class))
            : sprintf('#[Throws(%s::class, when: \'%s\')]', $this->displayName($class), $when);
    }

    private function displayName(string $class): string
    {
        return $this->reflectionProvider->getClass($class)->getDisplayName();
    }

    private function translator(Encoding $encoding, ClassNameResolver $classes, bool $isContract, ?int $line, string $thisRefusal, string $file): ExpressionTranslator
    {
        return new ExpressionTranslator($encoding, $this->types, $this->enums, $classes, $this->reflectionProvider, $this->initializers, $isContract, $line, $thisRefusal, file: $file);
    }

    private function usesThis(ClassMethod $method, MethodContract $contract): bool
    {
        $nodes = $method->stmts ?? [];
        foreach ([...$contract->requires, ...$contract->ensures, ...array_filter(array_map(static fn ($throws) => $throws->when, $contract->throws))] as $clause) {
            $nodes[] = $clause->expression;
        }

        return (new NodeFinder())->findFirst(
            $nodes,
            static fn (Node $node): bool => $node instanceof Variable && $node->name === 'this',
        ) !== null;
    }
}
