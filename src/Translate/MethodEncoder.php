<?php

declare(strict_types=1);

namespace PhpstanContracts\Translate;

use PhpstanContracts\Contract\MethodContract;
use PhpstanContracts\Exception\VerificationRejected;
use PhpstanContracts\Smt\Smt;
use PhpstanContracts\Smt\Sort;
use PhpstanContracts\Smt\SortKind;
use PhpstanContracts\Smt\Term;
use PhpParser\Node;
use PhpParser\Node\Expr\Variable;
use PhpParser\Node\Stmt\ClassMethod;
use PhpParser\NodeFinder;
use PHPStan\Analyser\Scope;
use PHPStan\Node\InClassMethodNode;
use PHPStan\Reflection\InitializerExprTypeResolver;
use PHPStan\Reflection\ReflectionProvider;

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
    ) {
    }

    public function encode(InClassMethodNode $node, Scope $scope, MethodContract $contract): Encoding
    {
        $method = $node->getOriginalNode();
        $encoding = new Encoding();

        /** @var array<string, Term> $entry */
        $entry = [];
        /** @var array<string, string> $signatureClasses */
        $signatureClasses = [];

        $declaringClass = $scope->getClassReflection();
        $thisRefusal = $method->isStatic()
            ? 'There is no $this in a static method.'
            : 'Object state ($this) is not part of the verified subset yet.';

        // An enum case or a readonly data object has no mutable state, so `$this` is an
        // implicit first parameter. It is declared only when used, so that the
        // counterexamples of other instance methods stay as they are.
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
                $this->collectClasses($thisSpec->sort, $signatureClasses);
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

            $spec = $this->types->map($scope->getVariableType($name), $param->getStartLine());
            $term = $encoding->declareInput($name, $spec->sort);
            $encoding->assert($this->domains->constraint($spec, $term, true));
            $this->collectClasses($spec->sort, $signatureClasses);

            $entry[$name] = $term;
        }

        $returnLine = ($method->returnType ?? $method)->getStartLine();
        $returnType = $node->getMethodReflection()->getOnlyVariant()->getReturnType();
        $resultSpec = $this->types->map($returnType, $returnLine);
        $this->collectClasses($resultSpec->sort, $signatureClasses);
        $result = $encoding->declareResult($resultSpec->sort);

        $classes = new ClassNameResolver($this->reflectionProvider, $scope->getNamespace(), $signatureClasses, $declaringClass);

        // A precondition that checks for null tells the clauses after it, the body and the
        // postconditions that the value is not null, just like an `if` would.
        $view = $entry;
        foreach ($contract->requires as $clause) {
            $contractExpressions = $this->translator($encoding, $classes, true, $clause->line, $thisRefusal);
            $encoding->assert($contractExpressions->translateBoolean($clause->expression, $view, Smt::bool(true)));
            $view = $contractExpressions->narrow($clause->expression, true, $view);
        }

        $encoding->endAssumptions();

        $statements = new StatementTranslator($this->translator($encoding, $classes, false, null, $thisRefusal), $encoding, $resultSpec->sort);
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

        $postconditionEnv = [...$view, 'result' => $result];
        foreach ($contract->ensures as $clause) {
            $contractExpressions = $this->translator($encoding, $classes, true, $clause->line, $thisRefusal);
            $encoding->ensures[] = new EnsuresCheck(
                $clause->source,
                $clause->line,
                $contractExpressions->translateBoolean($clause->expression, $postconditionEnv, Smt::bool(true)),
            );
        }

        return $encoding;
    }

    private function translator(Encoding $encoding, ClassNameResolver $classes, bool $isContract, ?int $line, string $thisRefusal): ExpressionTranslator
    {
        return new ExpressionTranslator($encoding, $this->types, $this->enums, $classes, $this->reflectionProvider, $this->initializers, $isContract, $line, $thisRefusal);
    }

    private function usesThis(ClassMethod $method, MethodContract $contract): bool
    {
        $nodes = $method->stmts ?? [];
        foreach ([...$contract->requires, ...$contract->ensures] as $clause) {
            $nodes[] = $clause->expression;
        }

        return (new NodeFinder())->findFirst(
            $nodes,
            static fn (Node $node): bool => $node instanceof Variable && $node->name === 'this',
        ) !== null;
    }

    /**
     * Short names of the enums and data classes in the signature, so that
     * contract strings can refer to them without a `use` statement.
     *
     * @param array<string, string> $classes
     */
    private function collectClasses(Sort $sort, array &$classes): void
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
                        $this->collectClasses($field, $classes);
                    }
                }
                break;
            case SortKind::Nullable:
                if ($sort->inner !== null) {
                    $this->collectClasses($sort->inner, $classes);
                }
                break;
            default:
                break;
        }
    }
}
