<?php

declare(strict_types=1);

namespace PhpstanContracts\Translate;

use PhpstanContracts\Exception\VerificationRejected;
use PhpstanContracts\Smt\EnumSort;
use PhpstanContracts\Smt\Smt;
use PhpstanContracts\Smt\Sort;
use PhpstanContracts\Smt\SortKind;
use PhpstanContracts\Smt\Term;
use PhpParser\Node;
use PhpParser\Node\Arg;
use PhpParser\Node\Expr;
use PhpParser\Node\Expr\BinaryOp;
use PhpParser\Node\Expr\BooleanNot;
use PhpParser\Node\Expr\Cast;
use PhpParser\Node\Expr\ClassConstFetch;
use PhpParser\Node\Expr\ConstFetch;
use PhpParser\Node\Expr\FuncCall;
use PhpParser\Node\Expr\Isset_;
use PhpParser\Node\Expr\Match_;
use PhpParser\Node\Expr\New_;
use PhpParser\Node\Expr\NullsafePropertyFetch;
use PhpParser\Node\Expr\PropertyFetch;
use PhpParser\Node\Expr\StaticCall;
use PhpParser\Node\Expr\Ternary;
use PhpParser\Node\Expr\UnaryMinus;
use PhpParser\Node\Expr\Variable;
use PhpParser\Node\Identifier;
use PhpParser\Node\Name;
use PhpParser\Node\Scalar\Float_;
use PhpParser\Node\Scalar\Int_;
use PhpParser\Node\Scalar\String_;
use PhpParser\PrettyPrinter\Standard;
use PHPStan\Reflection\ClassReflection;
use PHPStan\Reflection\InitializerExprContext;
use PHPStan\Reflection\InitializerExprTypeResolver;
use PHPStan\Reflection\ReflectionProvider;
use PHPStan\Type\FloatType;
use PHPStan\Type\IntegerType;
use PHPStan\Type\Type;
use PHPStan\Type\VerbosityLevel;

/**
 * Whitelist translation of PHP expressions to SMT terms. Anything not handled
 * here is rejected, which is what keeps verified methods pure.
 *
 * Every method receives the path condition under which the expression is
 * evaluated, so short-circuit operators, ternaries and match arms only incur
 * the safety obligations that are actually reachable.
 *
 * The environment maps a variable name to its current term. It also carries
 * narrowed views: after `$x !== null` the entry for `x` is the unwrapped value,
 * and `dto->field` entries hold a field that a null check has narrowed.
 */
final class ExpressionTranslator
{
    private const FLOAT_ORDER = [
        '<' => 'fp.lt',
        '<=' => 'fp.leq',
        '>' => 'fp.gt',
        '>=' => 'fp.geq',
    ];

    private readonly Standard $printer;

    /**
     * Whether the expression about to be translated is in tail position: its value is
     * the value of the whole statement, so a `throw` there ends the path and nothing
     * else is computed with the missing value.
     */
    private bool $tail = false;

    /**
     * @param bool $isContract contract expressions use unbounded integer semantics, so overflow is not checked
     * @param int|null $fixedLine line reported for contract expressions, whose own line numbers are meaningless
     */
    public function __construct(
        private readonly Encoding $encoding,
        private readonly TypeMapper $types,
        private readonly EnumRegistry $enums,
        private readonly ClassNameResolver $classes,
        private readonly ReflectionProvider $reflection,
        private readonly InitializerExprTypeResolver $initializers,
        private readonly bool $isContract,
        private readonly ?int $fixedLine = null,
        private readonly string $thisRefusal = 'Object state ($this) is not part of the verified subset yet.',
        private readonly ?MethodInliner $inliner = null,
        private readonly ?string $inlinedFrom = null,
        private readonly ?ThrowPolicy $throws = null,
    ) {
        $this->printer = new Standard();
    }

    /**
     * @param array<string, Term> $env
     */
    public function translate(Expr $expr, array $env, Term $pc): Term
    {
        $tail = $this->tail;
        $this->tail = false;

        return match (true) {
            $expr instanceof Int_ => Smt::int($expr->value),
            $expr instanceof Float_ => Smt::floatLiteral($expr->value),
            $expr instanceof String_ => Smt::stringLiteral($expr->value),
            $expr instanceof ConstFetch => $this->constant($expr),
            $expr instanceof Variable => $this->variable($expr, $env),
            $expr instanceof UnaryMinus => $this->unaryMinus($expr, $env, $pc),
            $expr instanceof BooleanNot => Smt::not($this->translateBoolean($expr->expr, $env, $pc)),
            $expr instanceof BinaryOp => $this->binary($expr, $env, $pc, $tail),
            $expr instanceof Ternary => $this->ternary($expr, $env, $pc, $tail),
            $expr instanceof Match_ => $this->match($expr, $env, $pc, $tail),
            $expr instanceof Expr\Throw_ => $this->throwExpression($expr, $env, $pc, $tail),
            $expr instanceof FuncCall => $this->functionCall($expr, $env, $pc),
            $expr instanceof ClassConstFetch => $this->classConstant($expr),
            $expr instanceof StaticCall => $this->staticCall($expr, $env, $pc),
            $expr instanceof Expr\MethodCall => $this->methodCall($expr, $env, $pc),
            $expr instanceof PropertyFetch => $this->propertyFetch($expr, $env, $pc),
            $expr instanceof NullsafePropertyFetch => $this->nullsafePropertyFetch($expr, $env, $pc),
            $expr instanceof New_ => $this->construct($expr, $env, $pc),
            $expr instanceof Isset_ => $this->issetExpression($expr, $env, $pc),
            $expr instanceof Cast\Int_ => $this->castToInt($expr, $env, $pc),
            $expr instanceof Cast\Double => $this->castToFloat($expr, $env, $pc),
            default => throw $this->reject($expr, $this->explainUnsupported($expr)),
        };
    }

    /**
     * Translates the value of a statement: the right side of an assignment, a returned
     * value, or a `throw` statement. A `throw` is allowed there, and in the branches of
     * `match`, `?:` and `??` that form that value.
     *
     * @param array<string, Term> $env
     */
    public function translateTail(Expr $expr, array $env, Term $pc): Term
    {
        return $this->translateBranch($expr, $env, $pc, true);
    }

    /**
     * The paths that reached a `throw` since the last call.
     *
     * @return list<Term>
     */
    public function takeThrows(): array
    {
        return $this->throws?->take() ?? [];
    }

    /**
     * @param array<string, Term> $env
     */
    private function translateBranch(Expr $expr, array $env, Term $pc, bool $tail): Term
    {
        $this->tail = $tail;

        return $this->translate($expr, $env, $pc);
    }

    /**
     * @param array<string, Term> $env
     */
    public function translateBoolean(Expr $expr, array $env, Term $pc): Term
    {
        $term = $this->translate($expr, $env, $pc);
        if (!$term->sort->isBool()) {
            throw $this->reject(
                $expr,
                sprintf('Expected a bool expression but got %s (implicit truthiness is not supported).', $term->sort->describe()),
            );
        }

        return $term;
    }

    /**
     * Gives a value the sort a variable, return type or field expects, wrapping
     * a plain value into a nullable one and turning `null` into the none value.
     */
    public function coerce(Node $at, Term $term, Sort $target): Term
    {
        if ($term->sort->equals($target)) {
            return $term;
        }
        if ($term->sort->isNull() && $target->isNullable()) {
            return Smt::none($target);
        }
        if ($target->isNullable() && $target->inner !== null && $term->sort->equals($target->inner)) {
            return Smt::some($term);
        }

        throw $this->reject($at, sprintf('Cannot use a %s where a %s is expected.', $term->sort->describe(), $target->describe()));
    }

    /**
     * What a condition tells about nullable variables and fields on the path where it is `$truth`.
     *
     * @param array<string, Term> $env
     * @return array<string, Term>
     */
    public function narrow(Expr $condition, bool $truth, array $env): array
    {
        if ($condition instanceof BooleanNot) {
            return $this->narrow($condition->expr, !$truth, $env);
        }

        if ($condition instanceof BinaryOp\BooleanAnd || $condition instanceof BinaryOp\LogicalAnd) {
            return $truth
                ? $this->narrow($condition->right, true, $this->narrow($condition->left, true, $env))
                : $env;
        }

        if ($condition instanceof BinaryOp\BooleanOr || $condition instanceof BinaryOp\LogicalOr) {
            return $truth
                ? $env
                : $this->narrow($condition->right, false, $this->narrow($condition->left, false, $env));
        }

        if ($condition instanceof BinaryOp\Identical || $condition instanceof BinaryOp\NotIdentical) {
            $subject = $this->subjectComparedToNull($condition);
            $nonNull = ($condition instanceof BinaryOp\NotIdentical) === $truth;

            return $subject !== null && $nonNull ? $this->markNonNull($subject, $env) : $env;
        }

        if ($condition instanceof FuncCall && $condition->name instanceof Name && strtolower($condition->name->toString()) === 'is_null') {
            $argument = $condition->args[0] ?? null;

            return $argument instanceof Arg && !$truth ? $this->markNonNull($argument->value, $env) : $env;
        }

        if ($condition instanceof Isset_ && $truth) {
            foreach ($condition->vars as $variable) {
                $env = $this->markNonNull($variable, $env);
            }
        }

        return $env;
    }

    public function reject(Node $at, string $message): VerificationRejected
    {
        return new VerificationRejected($message, $this->lineOf($at));
    }

    private function lineOf(Node $node): int
    {
        return $this->fixedLine ?? $node->getStartLine();
    }

    private function constant(ConstFetch $expr): Term
    {
        return match (strtolower(ltrim($expr->name->toString(), '\\'))) {
            'true' => Smt::bool(true),
            'false' => Smt::bool(false),
            'null' => Smt::nullLiteral(),
            'php_int_max' => Smt::int(PHP_INT_MAX),
            'php_int_min' => Smt::int(PHP_INT_MIN),
            'nan' => Smt::floatLiteral(NAN),
            'inf' => Smt::floatLiteral(INF),
            'php_float_epsilon' => Smt::floatLiteral(PHP_FLOAT_EPSILON),
            'php_float_max' => Smt::floatLiteral(PHP_FLOAT_MAX),
            'php_float_min' => Smt::floatLiteral(PHP_FLOAT_MIN),
            default => throw $this->reject($expr, sprintf('Constant %s is not part of the verified subset.', $expr->name->toString())),
        };
    }

    /**
     * @param array<string, Term> $env
     */
    private function variable(Variable $expr, array $env): Term
    {
        if (!is_string($expr->name)) {
            throw $this->reject($expr, 'Variable variables ($$name) are forbidden in verified methods.');
        }
        if ($expr->name === 'this') {
            // Present only in a method of an enum or a readonly data class, see MethodEncoder.
            return $env['this'] ?? throw $this->reject($expr, $this->thisRefusal);
        }

        return $env[$expr->name]
            ?? throw $this->reject($expr, sprintf('Variable $%s is not defined at this point.', $expr->name));
    }

    /**
     * @param array<string, Term> $env
     */
    private function unaryMinus(UnaryMinus $expr, array $env, Term $pc): Term
    {
        $operand = $this->translate($expr->expr, $env, $pc);

        return match (true) {
            $operand->sort->isInt() => $this->checked(Smt::negate($operand), $expr, $pc),
            $operand->sort->isFloat() => Smt::fpUnary('fp.neg', $operand),
            default => throw $this->reject($expr->expr, $this->expected('a number', $operand)),
        };
    }

    /**
     * @param array<string, Term> $env
     */
    private function binary(BinaryOp $expr, array $env, Term $pc, bool $tail): Term
    {
        if ($expr instanceof BinaryOp\BooleanAnd || $expr instanceof BinaryOp\LogicalAnd) {
            $left = $this->translateBoolean($expr->left, $env, $pc);
            $right = $this->translateBoolean(
                $expr->right,
                $this->narrow($expr->left, true, $env),
                Smt::all($pc, $left),
            );

            return Smt::all($left, $right);
        }

        if ($expr instanceof BinaryOp\BooleanOr || $expr instanceof BinaryOp\LogicalOr) {
            $left = $this->translateBoolean($expr->left, $env, $pc);
            $right = $this->translateBoolean(
                $expr->right,
                $this->narrow($expr->left, false, $env),
                Smt::all($pc, Smt::not($left)),
            );

            return Smt::any($left, $right);
        }

        if ($expr instanceof BinaryOp\Coalesce) {
            return $this->coalesce($expr, $env, $pc, $tail);
        }

        $left = $this->translate($expr->left, $env, $pc);
        $right = $this->translate($expr->right, $env, $pc);

        return match (true) {
            $expr instanceof BinaryOp\Plus => $this->arithmetic('+', 'add', $expr, $left, $right, $pc),
            $expr instanceof BinaryOp\Minus => $this->arithmetic('-', 'sub', $expr, $left, $right, $pc),
            $expr instanceof BinaryOp\Mul => $this->arithmetic('*', 'mul', $expr, $left, $right, $pc),
            $expr instanceof BinaryOp\Div => $this->division($expr, $left, $right, $pc),
            $expr instanceof BinaryOp\Mod => $this->modulo($expr, $left, $right, $pc),
            $expr instanceof BinaryOp\Smaller => $this->comparison('<', $expr, $left, $right),
            $expr instanceof BinaryOp\SmallerOrEqual => $this->comparison('<=', $expr, $left, $right),
            $expr instanceof BinaryOp\Greater => $this->comparison('>', $expr, $left, $right),
            $expr instanceof BinaryOp\GreaterOrEqual => $this->comparison('>=', $expr, $left, $right),
            $expr instanceof BinaryOp\Identical => $this->identical($expr, $left, $right),
            $expr instanceof BinaryOp\NotIdentical => Smt::not($this->identical($expr, $left, $right)),
            $expr instanceof BinaryOp\Equal => $this->looseEquals($expr, $left, $right),
            $expr instanceof BinaryOp\NotEqual => Smt::not($this->looseEquals($expr, $left, $right)),
            $expr instanceof BinaryOp\Concat => $this->concat($expr, $left, $right),
            default => throw $this->reject($expr, sprintf('Operator "%s" is not part of the verified subset.', $expr->getOperatorSigil())),
        };
    }

    /**
     * @param '+'|'-'|'*' $integerOperator
     * @param 'add'|'sub'|'mul' $floatOperation
     */
    private function arithmetic(string $integerOperator, string $floatOperation, BinaryOp $expr, Term $left, Term $right, Term $pc): Term
    {
        $this->requireNumber($expr->left, $left);
        $this->requireNumber($expr->right, $right);

        if ($left->sort->isInt() && $right->sort->isInt()) {
            return $this->checked(Smt::arithmetic($integerOperator, $left, $right), $expr, $pc);
        }

        [$left, $right] = $this->promote($left, $right);

        return $this->encoding->define('f', Smt::fpArithmetic($floatOperation, $left, $right));
    }

    private function division(BinaryOp $expr, Term $left, Term $right, Term $pc): Term
    {
        $this->requireNumber($expr->left, $left);
        $this->requireNumber($expr->right, $right);

        if ($left->sort->isInt() && $right->sort->isInt()) {
            throw $this->reject($expr, 'The "/" operator yields a float when the division is not exact; use intdiv().');
        }

        [$left, $right] = $this->promote($left, $right);
        $this->obligate(ObligationKind::DivisionByZero, $expr, $pc, Smt::not(Smt::fpIs('fp.isZero', $right)));

        return $this->encoding->define('f', Smt::fpArithmetic('div', $left, $right));
    }

    private function modulo(BinaryOp $expr, Term $left, Term $right, Term $pc): Term
    {
        $this->requireInt($expr->left, $left, 'The "%" operator converts its operands to int; use it on ints.');
        $this->requireInt($expr->right, $right, 'The "%" operator converts its operands to int; use it on ints.');
        $this->requireNonZero($expr, $right, $pc);

        return $this->encoding->define('mod', Smt::call('php_mod', Sort::int(), $left, $right));
    }

    /**
     * @param '<'|'<='|'>'|'>=' $operator
     */
    private function comparison(string $operator, BinaryOp $expr, Term $left, Term $right): Term
    {
        $this->requireNumber($expr->left, $left);
        $this->requireNumber($expr->right, $right);

        if ($left->sort->isInt() && $right->sort->isInt()) {
            return Smt::compare($operator, $left, $right);
        }

        [$left, $right] = $this->promote($left, $right);

        return Smt::fpCompare(self::FLOAT_ORDER[$operator], $left, $right);
    }

    /**
     * PHP `===`: same type and same value. An int never identical to a float.
     */
    private function identical(Expr $expr, Term $left, Term $right): Term
    {
        if ($left->sort->isNull() || $right->sort->isNull()) {
            $other = $left->sort->isNull() ? $right : $left;

            return match (true) {
                $other->sort->isNull() => Smt::bool(true),
                $other->sort->isNullable() => Smt::isNone($other),
                default => Smt::bool(false),
            };
        }

        if ($left->sort->isNullable() && $left->sort->inner !== null && $left->sort->inner->equals($right->sort)) {
            $right = Smt::some($right);
        } elseif ($right->sort->isNullable() && $right->sort->inner !== null && $right->sort->inner->equals($left->sort)) {
            $left = Smt::some($left);
        }

        if (!$left->sort->equals($right->sort)) {
            throw $this->reject($expr, sprintf(
                '%s === %s is always false in PHP; the operands have different types.',
                $left->sort->describe(),
                $right->sort->describe(),
            ));
        }

        if ($left->sort->kind === SortKind::Record) {
            throw $this->reject($expr, 'Objects are compared by identity with ===; compare their fields instead.');
        }
        if ($left->sort->isNullable() && $left->sort->inner?->kind === SortKind::Record) {
            throw $this->reject($expr, 'Objects are compared by identity with ===; compare their fields instead.');
        }

        return Smt::phpEquals($left, $right);
    }

    /**
     * PHP `==`, allowed only where it behaves like `===` or like numeric comparison.
     */
    private function looseEquals(Expr $expr, Term $left, Term $right): Term
    {
        if ($left->sort->isNull() || $right->sort->isNull()) {
            throw $this->reject($expr, 'Comparing with null using == is true for 0, "" and false; use === null.');
        }
        if ($left->sort->isString() || $right->sort->isString()) {
            throw $this->reject($expr, 'Strings compared with == are compared numerically when both look like numbers; use ===.');
        }
        if ($left->sort->isNumeric() && $right->sort->isNumeric()) {
            if ($left->sort->isInt() && $right->sort->isInt()) {
                return Smt::eq($left, $right);
            }
            [$left, $right] = $this->promote($left, $right);

            return Smt::fpCompare('fp.eq', $left, $right);
        }
        if ($left->sort->isNullable() || $right->sort->isNullable() || $left->sort->kind === SortKind::Record) {
            throw $this->reject($expr, 'Use === with nullable values and compare the fields of objects.');
        }
        if (!$left->sort->equals($right->sort)) {
            throw $this->reject($expr, sprintf('Cannot compare %s with %s.', $left->sort->describe(), $right->sort->describe()));
        }

        return Smt::eq($left, $right);
    }

    private function concat(BinaryOp $expr, Term $left, Term $right): Term
    {
        if (!$left->sort->isString() || !$right->sort->isString()) {
            throw $this->reject($expr, 'Concatenation is only supported between two strings.');
        }

        return $this->encoding->define('s', Smt::concat($left, $right));
    }

    /**
     * @param array<string, Term> $env
     */
    private function coalesce(BinaryOp\Coalesce $expr, array $env, Term $pc, bool $tail): Term
    {
        $left = $this->translate($expr->left, $env, $pc);

        if ($left->sort->isNull()) {
            return $this->translateBranch($expr->right, $env, $pc, $tail);
        }
        $inner = $left->sort->inner;
        if (!$left->sort->isNullable() || $inner === null) {
            return $left;
        }

        $left = $this->encoding->define('c', $left);
        $right = $this->translateBranch($expr->right, $env, Smt::all($pc, Smt::isNone($left)), $tail);
        if ($right->sort->isNever()) {
            $right = $this->encoding->fresh('unreached', $inner);
        }

        if ($right->sort->isNull()) {
            return $left;
        }
        if ($right->sort->equals($inner)) {
            return Smt::ite(Smt::isNone($left), $right, Smt::unwrap($left));
        }
        if ($right->sort->equals($left->sort)) {
            return Smt::ite(Smt::isNone($left), $right, $left);
        }

        throw $this->reject($expr, sprintf(
            'The right side of ?? is a %s but the left side is a %s.',
            $right->sort->describe(),
            $left->sort->describe(),
        ));
    }

    /**
     * @param array<string, Term> $env
     */
    private function ternary(Ternary $expr, array $env, Term $pc, bool $tail): Term
    {
        if ($expr->if === null) {
            throw $this->reject($expr, 'The short ternary "?:" relies on truthiness; write the condition explicitly.');
        }

        $condition = $this->translateBoolean($expr->cond, $env, $pc);
        $then = $this->translateBranch($expr->if, $this->narrow($expr->cond, true, $env), Smt::all($pc, $condition), $tail);
        $else = $this->translateBranch($expr->else, $this->narrow($expr->cond, false, $env), Smt::all($pc, Smt::not($condition)), $tail);
        [$then, $else] = $this->unify($expr, $then, $else);

        return Smt::ite($condition, $then, $else);
    }

    /**
     * @param array<string, Term> $env
     */
    private function match(Match_ $expr, array $env, Term $pc, bool $tail): Term
    {
        $subject = $this->translate($expr->cond, $env, $pc);
        $matchesTrue = $expr->cond instanceof ConstFetch && strtolower($expr->cond->name->toString()) === 'true';

        /** @var list<array{Term, Term}> $arms */
        $arms = [];
        $default = null;
        $remaining = $pc;
        $remainingEnv = $env;

        foreach ($expr->arms as $arm) {
            if ($arm->conds === null) {
                $default = $arm;
                continue;
            }

            $matches = [];
            $armEnv = $remainingEnv;
            foreach ($arm->conds as $condition) {
                $candidate = $this->translate($condition, $remainingEnv, $remaining);
                $matches[] = $this->identical($expr, $subject, $candidate);
                if ($matchesTrue) {
                    $armEnv = $this->narrow($condition, true, $armEnv);
                }
            }
            $armCondition = $this->encoding->define('arm', Smt::any(...$matches));

            $value = $this->translateBranch($arm->body, $armEnv, Smt::all($remaining, $armCondition), $tail);
            $arms[] = [$armCondition, $value];
            $remaining = Smt::all($remaining, Smt::not($armCondition));
            if ($matchesTrue) {
                foreach ($arm->conds as $condition) {
                    $remainingEnv = $this->narrow($condition, false, $remainingEnv);
                }
            }
        }

        if ($default !== null) {
            $fallback = $this->translateBranch($default->body, $remainingEnv, $remaining, $tail);
        } else {
            if ($arms === []) {
                throw $this->reject($expr, 'A match expression without arms is not supported.');
            }
            $this->obligate(ObligationKind::UnhandledMatch, $expr, $remaining, Smt::bool(false));
            $fallback = $arms[array_key_last($arms)][1];
        }

        $result = $fallback;
        foreach (array_reverse($arms) as [$armCondition, $value]) {
            [$value, $result] = $this->unify($expr, $value, $result);
            $result = Smt::ite($armCondition, $value, $result);
        }

        return $result;
    }

    /**
     * Brings two branch values to a common sort, lifting plain values and `null`
     * to a nullable when the other branch needs it.
     *
     * @return array{Term, Term}
     */
    private function unify(Node $at, Term $first, Term $second): array
    {
        if ($first->sort->equals($second->sort)) {
            return [$first, $second];
        }

        // A branch that throws has no value: any value of the other branch's type stands
        // for it, and the statement that holds the expression stops that path.
        if ($first->sort->isNever()) {
            return [$this->unreached($second), $second];
        }
        if ($second->sort->isNever()) {
            return [$first, $this->unreached($first)];
        }

        if ($first->sort->isNull() && !$second->sort->isNull()) {
            $target = $second->sort->isNullable() ? $second->sort : Sort::nullable($second->sort);

            return [Smt::none($target), $this->coerce($at, $second, $target)];
        }
        if ($second->sort->isNull() && !$first->sort->isNull()) {
            $target = $first->sort->isNullable() ? $first->sort : Sort::nullable($first->sort);

            return [$this->coerce($at, $first, $target), Smt::none($target)];
        }
        if ($first->sort->isNullable() && $first->sort->inner?->equals($second->sort) === true) {
            return [$first, Smt::some($second)];
        }
        if ($second->sort->isNullable() && $second->sort->inner?->equals($first->sort) === true) {
            return [Smt::some($first), $second];
        }

        throw $this->reject($at, sprintf(
            'Branches have different types (%s and %s).',
            $first->sort->describe(),
            $second->sort->describe(),
        ));
    }

    private function unreached(Term $like): Term
    {
        return $like->sort->isNull() ? $like : $this->encoding->fresh('unreached', $like->sort);
    }

    /**
     * `throw new E(...)`. The path ends here, which the statement translator applies,
     * and the throw must be allowed by a `#[Throws]` on that path.
     *
     * @param array<string, Term> $env
     */
    private function throwExpression(Expr\Throw_ $expr, array $env, Term $pc, bool $tail): Term
    {
        if ($this->throws === null) {
            throw $this->reject($expr, match (true) {
                $this->isContract => 'throw cannot appear in a contract.',
                $this->inlinedFrom !== null => 'throw is not supported in a called method yet: the exception would leave the caller from inside an expression.',
                default => 'throw is not supported here.',
            });
        }
        if (!$tail) {
            throw $this->reject($expr, 'throw is only supported as a statement, or as a whole branch of match, ?: or ?? whose value is returned or assigned.');
        }

        $new = $expr->expr;
        if (!$new instanceof New_ || !$new->class instanceof Name) {
            throw $this->reject($expr, 'Only `throw new Exception(...)` is supported: the exception must be built where it is thrown.');
        }

        $className = $this->classes->resolve($new->class, $this->lineOf($expr));
        if (!$this->reflection->hasClass($className)) {
            throw $this->reject($expr, sprintf('Class %s cannot be resolved.', $className));
        }
        $class = $this->reflection->getClass($className);
        if (!$class->implementsInterface(\Throwable::class) || $class->isAbstract() || $class->isInterface()) {
            throw $this->reject($expr, sprintf('%s is not a concrete Throwable class.', $class->getDisplayName()));
        }

        // The constructors of Exception and Error only store their arguments. Any other
        // constructor runs code that the proof would not see.
        $constructor = $class->getNativeReflection()->getConstructor();
        if ($constructor === null || !in_array($constructor->getDeclaringClass()->getName(), [\Exception::class, \Error::class], true)) {
            throw $this->reject($expr, sprintf(
                'Cannot throw %s: its constructor is not the one of Exception or Error, so it can run code that is not verified.',
                $class->getDisplayName(),
            ));
        }

        $expected = ['message' => [Sort::string(), 'a string'], 'code' => [Sort::int(), 'an int'], 'previous' => null];
        $seen = [];
        foreach ($new->args as $position => $argument) {
            if (!$argument instanceof Arg || $argument->unpack) {
                throw $this->reject($expr, 'Argument unpacking is not supported.');
            }
            $name = $argument->name?->toString() ?? array_keys($expected)[$position] ?? null;
            if ($name === null || !array_key_exists($name, $expected) || isset($seen[$name])) {
                throw $this->reject($expr, sprintf('Invalid arguments for the constructor of %s.', $class->getDisplayName()));
            }
            $seen[$name] = true;

            $value = $this->translate($argument->value, $env, $pc);
            if ($expected[$name] === null) {
                if (!$value->sort->isNull()) {
                    throw $this->reject($expr, 'The previous exception can only be null here.');
                }
                continue;
            }
            if (!$value->sort->equals($expected[$name][0])) {
                throw $this->reject($expr, sprintf('The %s of an exception must be %s, not %s.', $name, $expected[$name][1], $value->sort->describe()));
            }
        }

        $this->obligate(ObligationKind::UnexpectedThrow, $expr, $pc, $this->throws->allowed($class));
        $this->throws->record($pc);

        return Smt::never();
    }

    /**
     * @param array<string, Term> $env
     */
    private function functionCall(FuncCall $call, array $env, Term $pc): Term
    {
        if (!$call->name instanceof Name) {
            throw $this->reject($call, 'Dynamic function calls are forbidden in verified methods.');
        }
        if ($call->isFirstClassCallable()) {
            throw $this->reject($call, 'First-class callable syntax is not supported.');
        }

        $name = strtolower(ltrim($call->name->toString(), '\\'));
        if ($name === 'in_array') {
            return $this->inArray($call, $env, $pc);
        }

        $arguments = [];
        foreach ($call->args as $argument) {
            if (!$argument instanceof Arg || $argument->unpack || $argument->name !== null) {
                throw $this->reject($call, 'Named arguments and argument unpacking are not supported.');
            }
            $arguments[] = $argument->value;
        }

        if ($name === 'implies') {
            return $this->implication($call, $arguments, $env, $pc);
        }

        $arity = match ($name) {
            'abs', 'strlen', 'is_null', 'is_nan', 'is_infinite', 'is_finite', 'floor', 'ceil', 'sqrt' => 1,
            'intdiv', 'min', 'max', 'str_starts_with', 'str_ends_with', 'str_contains' => 2,
            default => throw $this->reject($call, sprintf(
                'Call to %s() is not part of the verified subset: purity cannot be established.',
                $name,
            )),
        };

        $terms = $this->translateArguments($call, $arguments, $arity, $env, $pc);

        return match ($name) {
            'intdiv' => $this->intdiv($call, $terms, $pc),
            'min' => $this->extremum($call, '<=', $terms),
            'max' => $this->extremum($call, '>=', $terms),
            'abs' => $this->absolute($call, $terms, $pc),
            'strlen' => Smt::length($this->requireString($call, $terms[0])),
            'str_starts_with' => Smt::call('str.prefixof', Sort::bool(), $this->requireString($call, $terms[1]), $this->requireString($call, $terms[0])),
            'str_ends_with' => Smt::call('str.suffixof', Sort::bool(), $this->requireString($call, $terms[1]), $this->requireString($call, $terms[0])),
            'str_contains' => Smt::call('str.contains', Sort::bool(), $this->requireString($call, $terms[0]), $this->requireString($call, $terms[1])),
            'is_null' => $this->isNull($terms[0]),
            'is_nan' => Smt::fpIs('fp.isNaN', $this->requireFloat($call, $terms[0])),
            'is_infinite' => Smt::fpIs('fp.isInfinite', $this->requireFloat($call, $terms[0])),
            'is_finite' => $this->isFinite($call, $terms[0]),
            'floor' => $this->encoding->define('f', Smt::fpRound('RTN', $this->toFloat($call, $terms[0]))),
            'ceil' => $this->encoding->define('f', Smt::fpRound('RTP', $this->toFloat($call, $terms[0]))),
            'sqrt' => $this->encoding->define('f', Smt::fpSqrt($this->toFloat($call, $terms[0]))),
        };
    }

    /**
     * @param list<Expr> $arguments
     * @param array<string, Term> $env
     * @return list<Term>
     */
    private function translateArguments(FuncCall $call, array $arguments, int $expected, array $env, Term $pc): array
    {
        if (count($arguments) !== $expected) {
            $function = $call->name instanceof Name ? $call->name->toString() : 'function';

            throw $this->reject($call, sprintf('%s() expects exactly %d argument(s) here.', $function, $expected));
        }

        return array_map(fn (Expr $argument): Term => $this->translate($argument, $env, $pc), $arguments);
    }

    /**
     * @param list<Term> $arguments
     */
    private function intdiv(FuncCall $call, array $arguments, Term $pc): Term
    {
        [$dividend, $divisor] = $arguments;
        $this->requireInt($call, $dividend);
        $this->requireInt($call, $divisor);
        $this->requireNonZero($call, $divisor, $pc);

        return $this->checked(Smt::call('php_intdiv', Sort::int(), $dividend, $divisor), $call, $pc);
    }

    /**
     * @param '<='|'>=' $operator
     * @param list<Term> $arguments
     */
    private function extremum(FuncCall $call, string $operator, array $arguments): Term
    {
        [$first, $second] = $arguments;
        if (!$first->sort->isInt() || !$second->sort->isInt()) {
            throw $this->reject($call, 'min() and max() are only supported on ints: PHP orders NaN and mixed int/float arguments in a way that is not modelled.');
        }
        $first = $this->encoding->define('a', $first);
        $second = $this->encoding->define('b', $second);

        return Smt::ite(Smt::compare($operator, $first, $second), $first, $second);
    }

    /**
     * @param list<Term> $arguments
     */
    private function absolute(FuncCall $call, array $arguments, Term $pc): Term
    {
        [$value] = $arguments;
        if ($value->sort->isFloat()) {
            return Smt::fpUnary('fp.abs', $value);
        }

        $this->requireInt($call, $value);
        $value = $this->encoding->define('a', $value);

        return $this->checked(
            Smt::ite(Smt::compare('>=', $value, Smt::int(0)), $value, Smt::negate($value)),
            $call,
            $pc,
        );
    }

    /**
     * The conclusion is read under the premise, so `implies($x !== null, $x > 0)` type-checks.
     *
     * @param list<Expr> $arguments
     * @param array<string, Term> $env
     */
    private function implication(FuncCall $call, array $arguments, array $env, Term $pc): Term
    {
        if (!$this->isContract) {
            throw $this->reject($call, 'implies() only exists inside contract expressions.');
        }
        if (count($arguments) !== 2) {
            throw $this->reject($call, 'implies() expects exactly 2 argument(s) here.');
        }

        $premise = $this->translateBoolean($arguments[0], $env, $pc);
        $conclusion = $this->translateBoolean(
            $arguments[1],
            $this->narrow($arguments[0], true, $env),
            Smt::all($pc, $premise),
        );

        return Smt::implies($premise, $conclusion);
    }

    /**
     * `in_array($needle, [a, b, c], $strict)` is `$needle === a || ...` when strict, and
     * `$needle == a || ...` otherwise, which is what PHP computes element by element.
     * The haystack must be a list literal, or a class constant holding one.
     *
     * @param array<string, Term> $env
     */
    private function inArray(FuncCall $call, array $env, Term $pc): Term
    {
        $slots = ['needle' => null, 'haystack' => null, 'strict' => null];
        foreach ($call->args as $position => $argument) {
            if (!$argument instanceof Arg || $argument->unpack) {
                throw $this->reject($call, 'Argument unpacking is not supported.');
            }
            $slot = $argument->name?->toString() ?? array_keys($slots)[$position] ?? null;
            if ($slot === null || !array_key_exists($slot, $slots) || $slots[$slot] !== null) {
                throw $this->reject($call, 'in_array() takes a needle, a haystack and an optional strict flag.');
            }
            $slots[$slot] = $argument->value;
        }
        if ($slots['needle'] === null || $slots['haystack'] === null) {
            throw $this->reject($call, 'in_array() takes a needle, a haystack and an optional strict flag.');
        }

        $strict = false;
        if ($slots['strict'] !== null) {
            $flag = $this->translate($slots['strict'], $env, $pc);
            if (!$flag->isTrue() && !$flag->isFalse()) {
                throw $this->reject($call, 'The strict argument of in_array() must be the literal true or false.');
            }
            $strict = $flag->isTrue();
        }

        $needle = $this->encoding->define('needle', $this->translate($slots['needle'], $env, $pc));
        if (!$strict && !in_array($needle->sort->kind, [SortKind::Int, SortKind::Float, SortKind::Bool, SortKind::Enum], true)) {
            throw $this->reject($call, sprintf(
                'in_array() without strict: true compares with ==, which juggles a %s; pass true as the third argument.',
                $needle->sort->describe(),
            ));
        }

        $matches = [];
        foreach ($this->listElements($slots['haystack'], $env, $pc) as $element) {
            $matches[] = $strict
                ? $this->identical($call, $needle, $element)
                : $this->looseEquals($call, $needle, $element);
        }

        return Smt::any(...$matches);
    }

    /**
     * @param array<string, Term> $env
     * @return list<Term>
     */
    private function listElements(Expr $list, array $env, Term $pc): array
    {
        if ($list instanceof ClassConstFetch) {
            [$type, $label] = $this->constantType($list);
            $arrays = $type->getConstantArrays();
            if (count($arrays) !== 1) {
                throw $this->reject($list, sprintf('Constant %s does not hold a single array literal.', $label));
            }

            return array_values(array_map(
                fn (Type $value): Term => $this->literalOfType($list, $value, sprintf('An element of %s', $label)),
                $arrays[0]->getValueTypes(),
            ));
        }

        if (!$list instanceof Expr\Array_) {
            throw $this->reject($list, 'in_array() is only supported on a list literal, such as [self::A, self::B], or a class constant holding one.');
        }

        $elements = [];
        foreach ($list->items as $item) {
            if ($item->key !== null || $item->byRef || $item->unpack) {
                throw $this->reject($list, 'in_array() is only supported on a list literal without keys, references or spreads.');
            }
            $elements[] = $this->translate($item->value, $env, $pc);
        }

        return $elements;
    }

    private function isNull(Term $term): Term
    {
        return match (true) {
            $term->sort->isNull() => Smt::bool(true),
            $term->sort->isNullable() => Smt::isNone($term),
            default => Smt::bool(false),
        };
    }

    private function isFinite(FuncCall $call, Term $term): Term
    {
        $float = $this->requireFloat($call, $term);

        return Smt::not(Smt::any(Smt::fpIs('fp.isNaN', $float), Smt::fpIs('fp.isInfinite', $float)));
    }

    /**
     * An enum case, or a class constant whose value PHPStan folds to one scalar or
     * one enum case, which is then inlined as a literal.
     */
    private function classConstant(ClassConstFetch $expr): Term
    {
        if ($expr->class instanceof Name && $expr->name instanceof Identifier && $expr->name->toLowerString() !== 'class') {
            $enum = $this->enums->find($this->classes->resolveStatically($expr->class, $this->lineOf($expr)));
            if ($enum !== null && array_key_exists($expr->name->toString(), $enum->cases)) {
                return $this->enumCaseTerm($enum, $expr->name->toString());
            }
        }

        [$type, $label] = $this->constantType($expr);

        return $this->literalOfType($expr, $type, 'Constant ' . $label);
    }

    /**
     * A single scalar or a single enum case, as a literal.
     */
    private function literalOfType(Expr $at, Type $type, string $subject): Term
    {
        $cases = $type->getEnumCases();
        if (count($cases) === 1 && $type->isObject()->yes()) {
            $caseEnum = $this->enums->find($cases[0]->getClassName());
            if ($caseEnum !== null && array_key_exists($cases[0]->getEnumCaseName(), $caseEnum->cases)) {
                return $this->enumCaseTerm($caseEnum, $cases[0]->getEnumCaseName());
            }
        }

        $values = $type->getConstantScalarValues();
        if (count($values) === 1) {
            $value = $values[0];

            return match (true) {
                is_int($value) => Smt::int($value),
                is_float($value) => Smt::floatLiteral($value),
                is_string($value) => Smt::stringLiteral($value),
                is_bool($value) => Smt::bool($value),
                default => Smt::nullLiteral(),
            };
        }

        throw $this->reject($at, sprintf(
            '%s has type %s; only constants holding a single int, float, string, bool or enum case are supported.',
            $subject,
            $type->describe(VerbosityLevel::typeOnly()),
        ));
    }

    /**
     * The value of a class constant, folded by PHPStan from its initializer.
     *
     * @return array{Type, string}
     */
    private function constantType(ClassConstFetch $expr): array
    {
        if (!$expr->class instanceof Name || !$expr->name instanceof Identifier) {
            throw $this->reject($expr, 'Dynamic class constant access is forbidden in verified methods.');
        }

        $name = $expr->name->toString();
        if (strtolower($name) === 'class') {
            throw $this->reject($expr, 'Class name constants (::class) are not part of the verified subset.');
        }

        // Late static binding is checked below, per constant: a final constant is safe even on a non-final class.
        $className = $this->classes->resolveStatically($expr->class, $this->lineOf($expr));
        $enum = $this->enums->find($className);

        if (!$this->reflection->hasClass($className)) {
            throw $this->reject($expr, sprintf('Class %s cannot be resolved.', $className));
        }
        $class = $this->reflection->getClass($className);
        if (!$class->hasConstant($name)) {
            throw $this->reject($expr, $enum !== null
                ? sprintf('Enum %s has no case %s.', $enum->shortName(), $name)
                : sprintf('Class %s has no constant %s.', $class->getDisplayName(), $name));
        }

        $constant = $class->getConstant($name);
        $label = sprintf('%s::%s', $class->getDisplayName(), $name);

        if ($this->classes->isLateStatic($expr->class) && !$this->classes->declaringClassIsFinal() && !$constant->isFinalByKeyword()) {
            throw $this->reject($expr, sprintf(
                'static::%s is late bound: a subclass of %s can override the constant. Use self::%s, or make the class or the constant final.',
                $name,
                $class->getDisplayName(),
                $name,
            ));
        }
        if (!$this->canAccess($constant->getDeclaringClass(), $constant->isPrivate(), $constant->isPublic())) {
            throw $this->reject($expr, sprintf('Constant %s is not accessible here.', $label));
        }

        // Not getValueType(): for a typed constant it is the declared type, which a subclass
        // may narrow. The access is statically bound here, so the initializer is the value.
        $type = $this->initializers->getType(
            $constant->getValueExpr(),
            InitializerExprContext::fromClassReflection($constant->getDeclaringClass()),
        );
        $nativeType = $constant->getNativeType();
        if (
            $nativeType !== null
            && $type->isInteger()->yes()
            && !$nativeType->isSuperTypeOf(new FloatType())->no()
            && $nativeType->isSuperTypeOf(new IntegerType())->no()
        ) {
            // `const float RATE = 1;` (also `?float`, `float|string`) holds 1.0.
            $type = $type->toFloat();
        }

        return [$type, $label];
    }

    private function enumCaseTerm(EnumSort $enum, string $case): Term
    {
        $sort = Sort::enum($enum);
        $this->encoding->registerSort($sort);

        return Smt::symbol($enum->constructorOf($case), $sort);
    }

    /**
     * PHPStan reports an inaccessible constant too, but a proof must not rest on code that throws.
     */
    private function canAccess(ClassReflection $owner, bool $isPrivate, bool $isPublic): bool
    {
        if ($isPublic) {
            return true;
        }

        $here = $this->classes->declaringClass();
        if ($here === null) {
            return false;
        }
        if ($isPrivate) {
            return $here->getName() === $owner->getName();
        }

        return $here->getName() === $owner->getName()
            || $here->isSubclassOfClass($owner)
            || $owner->isSubclassOfClass($here);
    }

    /**
     * `Enum::from($v)` and `Enum::tryFrom($v)`.
     *
     * @param array<string, Term> $env
     */
    private function staticCall(StaticCall $expr, array $env, Term $pc): Term
    {
        if (!$expr->class instanceof Name || !$expr->name instanceof Identifier) {
            throw $this->reject($expr, 'Dynamic static calls are forbidden in verified methods.');
        }

        $method = strtolower($expr->name->toString());
        $enum = $method === 'from' || $method === 'tryfrom'
            ? $this->enums->find($this->classes->resolveStatically($expr->class, $this->lineOf($expr)))
            : null;

        if ($enum === null) {
            return $this->inline(
                $expr,
                $this->classes->resolveStatically($expr->class, $this->lineOf($expr)),
                $expr->name->toString(),
                null,
                $this->classes->isLateStatic($expr->class),
                $env,
                $pc,
            );
        }
        if ($enum->backingType === null) {
            throw $this->reject($expr, sprintf('%s is not a backed enum, so it has no %s().', $enum->shortName(), $expr->name->toString()));
        }

        $argument = $expr->args[0] ?? null;
        if (count($expr->args) !== 1 || !$argument instanceof Arg || $argument->unpack || $argument->name !== null) {
            throw $this->reject($expr, sprintf('%s() expects exactly one positional argument.', $expr->name->toString()));
        }

        $value = $this->translate($argument->value, $env, $pc);
        $expected = $enum->backingType === 'int' ? Sort::int() : Sort::string();
        if (!$value->sort->equals($expected)) {
            throw $this->reject($expr, sprintf('%s::%s() expects a %s.', $enum->shortName(), $expr->name->toString(), $expected->describe()));
        }
        $value = $this->encoding->define('v', $value);

        $sort = Sort::enum($enum);
        $this->encoding->registerSort($sort);
        $nullable = Sort::nullable($sort);
        $this->encoding->registerSort($nullable);

        $matches = [];
        foreach ($enum->cases as $case => $backing) {
            $literal = is_int($backing) ? Smt::int($backing) : Smt::stringLiteral((string) $backing);
            $matches[(string) $case] = Smt::eq($value, $literal);
        }

        if ($method === 'from') {
            $this->obligate(ObligationKind::InvalidEnumValue, $expr, $pc, Smt::any(...array_values($matches)));
            $result = Smt::symbol($enum->constructorOf((string) array_key_last($enum->cases)), $sort);
            foreach (array_reverse($matches, true) as $case => $match) {
                $result = Smt::ite($match, Smt::symbol($enum->constructorOf((string) $case), $sort), $result);
            }

            return $result;
        }

        $result = Smt::none($nullable);
        foreach (array_reverse($matches, true) as $case => $match) {
            $result = Smt::ite($match, Smt::some(Smt::symbol($enum->constructorOf((string) $case), $sort)), $result);
        }

        return $result;
    }

    /**
     * `$object->method(...)` on an enum case or a data object.
     *
     * @param array<string, Term> $env
     */
    private function methodCall(Expr\MethodCall $expr, array $env, Term $pc): Term
    {
        if (!$expr->name instanceof Identifier) {
            throw $this->reject($expr, 'Dynamic method calls are forbidden in verified methods.');
        }
        $this->requireInliner($expr);

        $receiver = $this->translate($expr->var, $env, $pc);
        $className = match (true) {
            $receiver->sort->enum !== null => $receiver->sort->enum->className,
            $receiver->sort->record !== null => $receiver->sort->record->className,
            $receiver->sort->isNullable() => throw $this->reject($expr, sprintf('$%s may be null here; check it first.', $this->describeSubject($expr->var))),
            default => throw $this->reject($expr, sprintf('Method calls are only supported on enums and data classes, not on a %s.', $receiver->sort->describe())),
        };

        return $this->inline($expr, $className, $expr->name->toString(), $this->encoding->define('recv', $receiver), true, $env, $pc);
    }

    /**
     * @param array<string, Term> $env
     */
    private function inline(Expr\MethodCall|StaticCall $call, string $className, string $method, ?Term $receiver, bool $lateBound, array $env, Term $pc): Term
    {
        $inliner = $this->requireInliner($call);
        if ($call->isFirstClassCallable()) {
            throw $this->reject($call, 'First-class callable syntax is not supported.');
        }

        return $inliner->inline(
            $this,
            $this->classes->declaringClass(),
            $call,
            $this->lineOf($call),
            $className,
            $method,
            $receiver,
            $lateBound,
            $call->args,
            $env,
            $pc,
        );
    }

    private function requireInliner(Expr $call): MethodInliner
    {
        return $this->inliner ?? throw $this->reject($call, $this->isContract
            ? 'Method calls are not supported in contract expressions yet.'
            : 'Method calls and instantiations are not part of the verified subset yet: side effects cannot be ruled out.');
    }

    /**
     * @param array<string, Term> $env
     */
    private function propertyFetch(PropertyFetch $expr, array $env, Term $pc): Term
    {
        if (!$expr->name instanceof Identifier) {
            throw $this->reject($expr, 'Dynamic property access ($obj->$prop) is forbidden in verified methods.');
        }

        $path = $this->pathOf($expr);
        if ($path !== null && isset($env[$path])) {
            return $env[$path];
        }

        $object = $this->translate($expr->var, $env, $pc);
        $property = $expr->name->toString();

        if ($object->sort->enum !== null) {
            return $this->enumProperty($expr, $object, $property);
        }

        if ($object->sort->kind === SortKind::Record && $object->sort->record !== null) {
            if (!isset($object->sort->record->fields[$property])) {
                throw $this->reject($expr, $object->sort->record->unmodelled[$property]
                    ?? sprintf('%s has no property $%s.', $object->sort->record->shortName(), $property));
            }

            return Smt::field($object, $property);
        }

        if ($object->sort->isNullable()) {
            throw $this->reject($expr, sprintf('$%s may be null here; check it first or use ?->.', $this->describeSubject($expr->var)));
        }

        throw $this->reject($expr, sprintf('Property access ->%s is not supported on a %s.', $property, $object->sort->describe()));
    }

    private function enumProperty(Expr $at, Term $object, string $property): Term
    {
        $enum = $object->sort->enum;
        if ($enum === null) {
            throw $this->reject($at, 'Not an enum.');
        }

        if ($property === 'name') {
            $this->encoding->requireEnumFunction($enum, 'name');

            return Smt::call($enum->nameFunction(), Sort::string(), $object);
        }

        if ($property === 'value' && $enum->backingType !== null) {
            $this->encoding->requireEnumFunction($enum, 'value');

            return Smt::call($enum->valueFunction(), $enum->backingType === 'int' ? Sort::int() : Sort::string(), $object);
        }

        throw $this->reject($at, $property === 'value'
            ? sprintf('%s is not a backed enum, so it has no ->value.', $enum->shortName())
            : sprintf('Property ->%s is not available on enum %s.', $property, $enum->shortName()));
    }

    /**
     * `$a?->b`: null when `$a` is null, else the field.
     *
     * @param array<string, Term> $env
     */
    private function nullsafePropertyFetch(NullsafePropertyFetch $expr, array $env, Term $pc): Term
    {
        if (!$expr->name instanceof Identifier) {
            throw $this->reject($expr, 'Dynamic property access ($obj?->$prop) is forbidden in verified methods.');
        }

        $object = $this->translate($expr->var, $env, $pc);
        $property = $expr->name->toString();

        if ($object->sort->isNull()) {
            return Smt::nullLiteral();
        }

        $nullable = $object->sort->isNullable();
        $record = $nullable ? $object->sort->inner : $object->sort;
        $unmodelled = $record?->record?->unmodelled[$property] ?? null;
        if ($unmodelled !== null) {
            throw $this->reject($expr, $unmodelled);
        }
        if ($record === null || $record->kind !== SortKind::Record || $record->record === null || !isset($record->record->fields[$property])) {
            throw $this->reject($expr, sprintf('Property access ?->%s is not supported on a %s.', $property, $object->sort->describe()));
        }

        $object = $this->encoding->define('o', $object);
        $field = Smt::field($nullable ? Smt::unwrap($object) : $object, $property);
        if (!$nullable) {
            return $field;
        }

        $fieldSort = $field->sort->isNullable() ? $field->sort : Sort::nullable($field->sort);
        $this->encoding->registerSort($fieldSort);

        return Smt::ite(Smt::isNone($object), Smt::none($fieldSort), $this->coerce($expr, $field, $fieldSort));
    }

    /**
     * `new Data(...)` for a data class whose constructor only promotes its properties.
     *
     * @param array<string, Term> $env
     */
    private function construct(New_ $expr, array $env, Term $pc): Term
    {
        if (!$expr->class instanceof Name) {
            throw $this->reject($expr, 'Dynamic instantiation is forbidden in verified methods.');
        }

        $className = $this->classes->resolve($expr->class, $this->lineOf($expr));
        $spec = $this->types->mapClass($className, $this->lineOf($expr));
        $record = $spec->sort->record;

        if ($record === null) {
            throw $this->reject($expr, sprintf('Cannot instantiate %s: only readonly data classes can be constructed.', $className));
        }
        if ($record->constructorOrder === null) {
            throw $this->reject($expr, sprintf('Cannot instantiate %s: %s.', $record->shortName(), $record->constructionRefusal));
        }

        $values = [];
        foreach ($expr->args as $position => $argument) {
            if (!$argument instanceof Arg || $argument->unpack) {
                throw $this->reject($expr, 'Argument unpacking is not supported.');
            }

            $field = $argument->name !== null ? $argument->name->toString() : ($record->constructorOrder[$position] ?? null);
            if ($field === null || !isset($record->fields[$field]) || isset($values[$field])) {
                throw $this->reject($expr, sprintf('Invalid argument for the constructor of %s.', $record->shortName()));
            }

            $values[$field] = $this->coerce(
                $argument->value,
                $this->translate($argument->value, $env, $pc),
                $record->fields[$field],
            );
        }

        if (count($values) !== count($record->fields)) {
            throw $this->reject($expr, sprintf('Every constructor argument of %s must be passed explicitly.', $record->shortName()));
        }

        $this->encoding->registerSort($spec->sort);

        return $this->encoding->define('obj', Smt::construct(
            $spec->sort,
            array_map(static fn (string $field): Term => $values[$field], array_keys($record->fields)),
        ));
    }

    /**
     * @param array<string, Term> $env
     */
    private function issetExpression(Isset_ $expr, array $env, Term $pc): Term
    {
        $checks = [];
        foreach ($expr->vars as $variable) {
            $term = $this->translate($variable, $env, $pc);
            $checks[] = $term->sort->isNullable() ? Smt::not(Smt::isNone($term)) : Smt::bool(!$term->sort->isNull());
        }

        return Smt::all(...$checks);
    }

    /**
     * @param array<string, Term> $env
     */
    private function castToInt(Cast\Int_ $expr, array $env, Term $pc): Term
    {
        $operand = $this->translate($expr->expr, $env, $pc);

        if ($operand->sort->isInt()) {
            return $operand;
        }
        if (!$operand->sort->isFloat()) {
            throw $this->reject($expr, sprintf('(int) of a %s is not supported: only int and float operands are.', $operand->sort->describe()));
        }

        $operand = $this->encoding->define('f', $operand);
        $this->obligate(ObligationKind::InvalidCast, $expr, $pc, Smt::all(
            Smt::fpCompare('fp.geq', $operand, Smt::floatLiteral(-9223372036854775808.0)),
            Smt::fpCompare('fp.lt', $operand, Smt::floatLiteral(9223372036854775808.0)),
        ));

        return $this->encoding->define('i', Smt::floatToInt($operand));
    }

    /**
     * @param array<string, Term> $env
     */
    private function castToFloat(Cast\Double $expr, array $env, Term $pc): Term
    {
        return $this->toFloat($expr, $this->translate($expr->expr, $env, $pc));
    }

    /**
     * @param array<string, Term> $env
     * @return array<string, Term>
     */
    private function markNonNull(Expr $subject, array $env): array
    {
        $path = $this->pathOf($subject);
        if ($path === null) {
            return $env;
        }

        $current = $env[$path] ?? null;
        if ($current === null && $subject instanceof PropertyFetch) {
            try {
                $current = $this->translate($subject, $env, Smt::bool(true));
            } catch (VerificationRejected) {
                return $env;
            }
        }

        if ($current === null || !$current->sort->isNullable()) {
            return $env;
        }

        $env[$path] = $this->encoding->define('nn', Smt::unwrap($current));

        return $env;
    }

    private function subjectComparedToNull(BinaryOp $comparison): ?Expr
    {
        $isNull = static fn (Expr $e): bool => $e instanceof ConstFetch && strtolower($e->name->toString()) === 'null';

        return match (true) {
            $isNull($comparison->right) => $comparison->left,
            $isNull($comparison->left) => $comparison->right,
            default => null,
        };
    }

    /**
     * The environment key under which a variable or a property chain is narrowed.
     */
    private function pathOf(Expr $expr): ?string
    {
        if ($expr instanceof Variable && is_string($expr->name)) {
            return $expr->name;
        }

        if ($expr instanceof PropertyFetch && $expr->name instanceof Identifier) {
            $base = $this->pathOf($expr->var);

            return $base === null ? null : $base . '->' . $expr->name->toString();
        }

        return null;
    }

    private function describeSubject(Expr $expr): string
    {
        return $this->pathOf($expr) ?? $this->printer->prettyPrintExpr($expr);
    }

    private function checked(Term $value, Expr $at, Term $pc): Term
    {
        $value = $this->encoding->define('t', $value);

        if (!$this->isContract) {
            $this->obligate(ObligationKind::Overflow, $at, $pc, Smt::inRange($value, PHP_INT_MIN, PHP_INT_MAX));
        }

        return $value;
    }

    private function requireNonZero(Expr $at, Term $divisor, Term $pc): void
    {
        $this->obligate(ObligationKind::DivisionByZero, $at, $pc, Smt::not(Smt::eq($divisor, Smt::int(0))));
    }

    private function obligate(ObligationKind $kind, Expr $at, Term $pc, Term $goal): void
    {
        $this->encoding->addObligation(new Obligation(
            $kind,
            $this->lineOf($at),
            $this->printer->prettyPrintExpr($at),
            $pc,
            $goal,
            $this->inlinedFrom,
        ));
    }

    private function requireInt(Node $at, Term $term, string $message = ''): Term
    {
        if (!$term->sort->isInt()) {
            throw $this->reject($at, $message !== '' ? $message : $this->expected('an int operand', $term));
        }

        return $term;
    }

    private function requireNumber(Node $at, Term $term): Term
    {
        if (!$term->sort->isNumeric()) {
            throw $this->reject($at, $this->expected('a number (int or float)', $term));
        }

        return $term;
    }

    private function requireFloat(Node $at, Term $term): Term
    {
        if (!$term->sort->isFloat()) {
            throw $this->reject($at, $this->expected('a float', $term));
        }

        return $term;
    }

    private function requireString(Node $at, Term $term): Term
    {
        if (!$term->sort->isString()) {
            throw $this->reject($at, $this->expected('a string', $term));
        }

        return $term;
    }

    private function expected(string $wanted, Term $got): string
    {
        return $got->sort->isNullable()
            ? sprintf('Expected %s but got %s, which may be null here: check it first with `!== null`, `isset()` or `??`.', $wanted, $got->sort->describe())
            : sprintf('Expected %s but got %s.', $wanted, $got->sort->describe());
    }

    private function toFloat(Node $at, Term $term): Term
    {
        $this->requireNumber($at, $term);

        return $term->sort->isFloat() ? $term : $this->encoding->define('f', Smt::intToFloat($term));
    }

    /**
     * Mixed int and float operands are computed as floats, with the int converted first.
     *
     * @return array{Term, Term}
     */
    private function promote(Term $left, Term $right): array
    {
        return [
            $left->sort->isFloat() ? $left : $this->encoding->define('f', Smt::intToFloat($left)),
            $right->sort->isFloat() ? $right : $this->encoding->define('f', Smt::intToFloat($right)),
        ];
    }

    private function explainUnsupported(Expr $expr): string
    {
        return match (true) {
            $expr instanceof Expr\Assign, $expr instanceof Expr\AssignOp, $expr instanceof Expr\AssignRef
                => 'Assignments are only allowed as standalone statements on local variables.',
            $expr instanceof Expr\MethodCall, $expr instanceof Expr\NullsafeMethodCall
                => 'Method calls and instantiations are not part of the verified subset yet: side effects cannot be ruled out.',
            $expr instanceof Node\Scalar\InterpolatedString
                => 'String interpolation is not supported; concatenate strings with ".".',
            $expr instanceof Expr\Array_, $expr instanceof Expr\ArrayDimFetch
                => 'Arrays are not supported yet.',
            $expr instanceof Cast
                => 'Only (int) and (float) casts are supported.',
            $expr instanceof Expr\Closure, $expr instanceof Expr\ArrowFunction
                => 'Closures are not supported in verified methods.',
            $expr instanceof Expr\Include_, $expr instanceof Expr\Eval_, $expr instanceof Expr\Exit_, $expr instanceof Expr\Print_, $expr instanceof Expr\ShellExec
                => 'Side effects and dynamic code are forbidden in verified methods.',
            default => sprintf('Expression %s is not part of the verified subset.', $expr->getType()),
        };
    }
}
