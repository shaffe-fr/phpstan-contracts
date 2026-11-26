<?php

declare(strict_types=1);

namespace PhpstanContracts\Translate;

use LogicException;
use PhpstanContracts\Exception\VerificationRejected;
use PhpstanContracts\Smt\EnumSort;
use PhpstanContracts\Smt\ShapeSort;
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
use PHPStan\Type\FloatType;
use PHPStan\Type\IntegerType;
use PHPStan\Type\MixedType;
use PHPStan\Type\StaticType;
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
    private const IN_ARRAY_HAYSTACK = 'in_array() is only supported on a list<T>, or on a list literal such as [self::A, self::B] or a class constant holding one.';

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
     * @param list<string> $calls in an inlined method, the calls that lead to it from the verified method
     * @param string|null $statelessThis the class whose `$this` is a value without state: it can only be the receiver of a call
     */
    public function __construct(
        private readonly Encoding $encoding,
        private readonly TypeMapper $types,
        private readonly EnumRegistry $enums,
        private readonly ClassNameResolver $classes,
        private readonly Lookups $lookups,
        private readonly InitializerExprTypeResolver $initializers,
        private readonly bool $isContract,
        private readonly ?int $fixedLine = null,
        private readonly string $thisRefusal = 'Object state ($this) is not part of the verified subset yet.',
        private readonly ?MethodInliner $inliner = null,
        private readonly array $calls = [],
        private readonly ?ThrowPolicy $throws = null,
        private readonly ?string $statelessThis = null,
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
            $expr instanceof StaticCall => $this->staticCall($expr, $env, $pc, $tail),
            $expr instanceof Expr\MethodCall => $this->methodCall($expr, $env, $pc, $tail),
            $expr instanceof PropertyFetch => $this->propertyFetch($expr, $env, $pc),
            $expr instanceof NullsafePropertyFetch => $this->nullsafePropertyFetch($expr, $env, $pc),
            $expr instanceof New_ => $this->construct($expr, $env, $pc),
            $expr instanceof Isset_ => $this->issetExpression($expr, $env, $pc),
            $expr instanceof Cast\Int_ => $this->castToInt($expr, $env, $pc),
            $expr instanceof Cast\Double => $this->castToFloat($expr, $env, $pc),
            $expr instanceof Cast\String_ => $this->toPhpString($expr, $this->translate($expr->expr, $env, $pc), true),
            $expr instanceof Node\Scalar\InterpolatedString => $this->interpolate($expr, $env, $pc),
            $expr instanceof Expr\Array_ => $this->arrayLiteral($expr, $env, $pc),
            $expr instanceof Expr\ArrayDimFetch => $this->arrayDimFetch($expr, $env, $pc),
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
     * The exceptions this body may throw and the paths that threw, null where a
     * `throw` cannot appear.
     */
    public function throwPolicy(): ?ThrowPolicy
    {
        return $this->throws;
    }

    /**
     * The line a diagnostic about `$node` is reported at: the line of the call when this
     * translates the body of a called method, which can be in another file.
     */
    public function reportedLine(Node $node): int
    {
        return $this->lineOf($node);
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
                sprintf(
                    'Expected a bool expression but got %s (implicit truthiness is not supported).%s',
                    $term->sort->describe(),
                    $term->sort->isArrayLike() ? ' Write count($list) > 0 or count($list) === 0 to test whether a list is empty.' : '',
                ),
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
        if ($term->sort->isEmptyList()) {
            $list = $target->isNullable() ? $target->inner : $target;
            if ($list !== null && $list->isList()) {
                $empty = $this->emptyListOf($list);
                $this->encoding->registerSort($target);

                return $target->isNullable() ? Smt::some($empty) : $empty;
            }
        }
        if ($term->sort->isNull() && $target->isNullable()) {
            return $this->none($target);
        }
        if ($target->isNullable() && $target->inner !== null && $term->sort->equals($target->inner)) {
            return $this->some($term);
        }
        if ($term->sort->isShape() && $this->canCoerce($term->sort, $target)) {
            $inner = $target->isNullable() ? $target->inner : $target;
            if ($inner !== null) {
                $rebuilt = $this->rebuildShape($at, $term, $inner);

                return $target->isNullable() ? Smt::some($rebuilt) : $rebuilt;
            }
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

        if (
            $condition instanceof FuncCall
            && $condition->name instanceof Name
            && strtolower($condition->name->toString()) === 'is_null'
            && $this->isGlobalFunction($condition->name, 'is_null')
        ) {
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
        $name = ltrim($expr->name->toString(), '\\');

        // true, false and null are the only case-insensitive constants, and no namespace can declare them.
        $keyword = match (strtolower($name)) {
            'true' => Smt::bool(true),
            'false' => Smt::bool(false),
            'null' => Smt::nullLiteral(),
            default => null,
        };
        if ($keyword !== null) {
            return $keyword;
        }

        if (!$this->isGlobalConstant($expr->name, $name)) {
            throw $this->reject($expr, sprintf(
                'Constant %s\\%s is not part of the verified subset: PHP reads it instead of the built-in constant.',
                $this->classes->namespace(),
                $name,
            ));
        }

        return match ($name) {
            'PHP_INT_MAX' => Smt::int(PHP_INT_MAX),
            'PHP_INT_MIN' => Smt::int(PHP_INT_MIN),
            'NAN' => Smt::floatLiteral(NAN),
            'INF' => Smt::floatLiteral(INF),
            'PHP_FLOAT_EPSILON' => Smt::floatLiteral(PHP_FLOAT_EPSILON),
            'PHP_FLOAT_MAX' => Smt::floatLiteral(PHP_FLOAT_MAX),
            'PHP_FLOAT_MIN' => Smt::floatLiteral(PHP_FLOAT_MIN),
            default => throw $this->reject($expr, sprintf('Constant %s is not part of the verified subset.', $expr->name->toString())),
        };
    }

    /**
     * Whether a constant resolves to the global one: an unqualified name in a namespace
     * reads the constant of that namespace when there is one, as PHP does at runtime.
     */
    private function isGlobalConstant(Name $name, string $constant): bool
    {
        $namespace = $this->classes->namespace();
        if ($name->isFullyQualified() || $namespace === null || $namespace === '') {
            return true;
        }

        return !$this->lookups->hasConstant(new Name\FullyQualified($namespace . '\\' . $constant), null);
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
            // A stateless class has no `$this` term: it is only the receiver of a call, see methodCall().
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
            if ($integerOperator === '*' && !$this->isConstant($left) && !$this->isConstant($right)) {
                $this->encoding->noteNonLinear('product', $this->printer->prettyPrintExpr($expr));
            }
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
        if (!$this->isConstant($right)) {
            $this->encoding->noteNonLinear('modulo', $this->printer->prettyPrintExpr($expr));
        }

        return $this->encoding->define('mod', Smt::call('php_mod', Sort::int(), $left, $right));
    }

    /**
     * @param '<'|'<='|'>'|'>=' $operator
     */
    private function comparison(string $operator, BinaryOp $expr, Term $left, Term $right): Term
    {
        if ($left->sort->isArrayLike() || $right->sort->isArrayLike()) {
            throw $this->reject($expr, sprintf('Arrays cannot be ordered with %s: PHP compares them by count, then key by key, which is not modelled. Compare count($list) or the elements.', $operator));
        }
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

        if ($left->sort->isArrayLike() || $right->sort->isArrayLike()) {
            return $this->identicalArrays($expr, $left, $right);
        }

        if ($left->sort->isNullable() && $left->sort->inner !== null && $left->sort->inner->equals($right->sort)) {
            $right = $this->some($right);
        } elseif ($right->sort->isNullable() && $right->sort->inner !== null && $right->sort->inner->equals($left->sort)) {
            $left = $this->some($left);
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
     * `===` where an operand is an array. Two lists are identical when they have the same
     * count and an identical element at every key, which is exact for elements that compare
     * by value. For floats it is not: PHP answers true at once for the same array in memory,
     * so `[NAN] === [NAN]` is false but `$a === $a` is true for `$a = [NAN]`.
     */
    private function identicalArrays(Expr $expr, Term $left, Term $right): Term
    {
        $isShape = static fn (Sort $sort): bool => ($sort->isNullable() ? $sort->inner : $sort)?->isShape() === true;
        if ($isShape($left->sort) || $isShape($right->sort)) {
            throw $this->reject($expr, $this->shapeIdentityRefusal());
        }
        if ($left->sort->isNullable() || $right->sort->isNullable()) {
            throw $this->reject($expr, sprintf(
                'A list that may be null (%s === %s) is not compared with === yet: check it with `!== null` first, then compare the lists.',
                $left->sort->describe(),
                $right->sort->describe(),
            ));
        }
        $isList = static fn (Sort $sort): bool => $sort->isList() || $sort->isEmptyList();
        if (!$isList($left->sort) || !$isList($right->sort)) {
            throw $this->reject($expr, sprintf(
                '%s === %s is always false in PHP; the operands have different types.',
                $left->sort->describe(),
                $right->sort->describe(),
            ));
        }

        if ($left->sort->isEmptyList() && $right->sort->isEmptyList()) {
            return Smt::bool(true);
        }
        // `$list === []` holds exactly when the list is empty, whatever its elements are.
        if ($left->sort->isEmptyList() || $right->sort->isEmptyList()) {
            $list = $left->sort->isEmptyList() ? $right : $left;

            return Smt::eq(Smt::listLength($list), Smt::int(0));
        }

        if (!$left->sort->equals($right->sort)) {
            throw $this->reject($expr, sprintf(
                'Lists of different element types (%s and %s) are not compared with ===: convert the elements first, or compare count() and the elements.',
                $left->sort->describe(),
                $right->sort->describe(),
            ));
        }

        $element = $left->sort->element ?? throw new LogicException('A list sort has an element sort.');
        $plain = $element->isNullable() && $element->inner !== null ? $element->inner : $element;
        if ($plain->isFloat()) {
            throw $this->reject($expr, 'Lists of floats cannot be compared with ===: PHP first checks whether both are the same array in memory, so [NAN] === [NAN] is false while $a === $a is true for $a = [NAN]. Compare count() and the elements instead, such as count($a) === count($b) && $a[0] === $b[0].');
        }
        if ($plain->isShape()) {
            throw $this->reject($expr, $this->shapeIdentityRefusal());
        }
        if ($plain->kind === SortKind::Record) {
            throw $this->reject($expr, 'Lists of objects cannot be compared with ===: objects are compared by identity; compare the fields of the elements.');
        }

        return Smt::listIdentical($this->encoding->define('list', $left), $this->encoding->define('list', $right));
    }

    /**
     * PHP `==`, allowed only where it behaves like `===` or like numeric comparison.
     */
    private function looseEquals(Expr $expr, Term $left, Term $right): Term
    {
        if ($left->sort->isArrayLike() || $right->sort->isArrayLike()) {
            throw $this->reject($expr, 'Arrays compared with == are compared loosely, with type juggling on every element ([1] == [true], [\'1e1\'] == [\'10\']); use === on lists, or compare the elements.');
        }
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
        return $this->encoding->define('s', Smt::concat($this->toPhpString($expr->left, $left), $this->toPhpString($expr->right, $right)));
    }

    /**
     * `"Ships in $days days"`: the parts, each converted to a string, concatenated.
     *
     * @param array<string, Term> $env
     */
    private function interpolate(Node\Scalar\InterpolatedString $expr, array $env, Term $pc): Term
    {
        $result = Smt::stringLiteral('');
        foreach ($expr->parts as $part) {
            $piece = $part instanceof Node\InterpolatedStringPart
                ? Smt::stringLiteral($part->value)
                : $this->toPhpString($part, $this->translate($part, $env, $pc));
            $result = $result->smt === '""' ? $piece : Smt::concat($result, $piece);
        }

        return $this->encoding->define('s', $result);
    }

    /**
     * What `.`, interpolation and `(string)` do to a value: a string is itself, an int
     * becomes its decimal digits with a leading `-` when negative, as `php_int_to_string`
     * defines. A float depends on the `precision` setting, so it is not modelled.
     *
     * @param bool $cast whether the conversion is a `(string)` cast, rather than `.` or an interpolation
     */
    private function toPhpString(Node $at, Term $term, bool $cast = false): Term
    {
        if ($term->sort->isString()) {
            return $term;
        }
        if ($term->sort->isInt()) {
            return Smt::call('php_int_to_string', Sort::string(), $term);
        }

        throw $this->reject($at, $cast
            ? sprintf('(string) of a %s is not supported: only ints and strings are converted.', $term->sort->describe())
            : sprintf('Converting a %s to a string is not supported: only strings and ints can be concatenated or interpolated.', $term->sort->describe()));
    }

    /**
     * @param array<string, Term> $env
     */
    private function coalesce(BinaryOp\Coalesce $expr, array $env, Term $pc, bool $tail): Term
    {
        $this->rejectQuietListChain($expr->left, $env, $pc, '??');
        $read = $expr->left instanceof Expr\ArrayDimFetch && $expr->left->dim !== null ? $expr->left : null;
        $base = $read === null ? null : $this->translate($read->var, $env, $pc);
        if ($read !== null && $base !== null && $base->sort->isList()) {
            return $this->coalesceListRead($expr, $this->listPresence($read, $base, $env, $pc), $env, $pc, $tail);
        }

        $left = $read !== null && $base !== null
            ? $this->arrayDimFetch($read, $env, $pc, $base)
            : $this->translate($expr->left, $env, $pc);

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
        if ($right->sort->isEmptyList() && $this->canCoerce($right->sort, $inner)) {
            $right = $this->coerce($expr, $right, $inner);
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
     * `$list[$i] ?? $default`, given whether the key is there and holds a value. On a
     * `list<?T>` an element that is `null` counts as missing, as in PHP.
     *
     * @param array{Term, Term} $read the condition that the element is set, and the element
     * @param array<string, Term> $env
     */
    private function coalesceListRead(BinaryOp\Coalesce $expr, array $read, array $env, Term $pc, bool $tail): Term
    {
        [$present, $value] = $read;
        $inner = $value->sort;

        $right = $this->translateBranch($expr->right, $env, Smt::all($pc, Smt::not($present)), $tail);
        if ($right->sort->isNever()) {
            $right = $this->encoding->fresh('unreached', $inner);
        }
        if ($right->sort->isEmptyList() && $this->canCoerce($right->sort, $inner)) {
            $right = $this->coerce($expr, $right, $inner);
        }

        $nullable = Sort::nullable($inner);
        if ($right->sort->isNull()) {
            $this->encoding->registerSort($nullable);

            return Smt::ite($present, Smt::some($value), Smt::none($nullable));
        }
        if ($right->sort->equals($inner)) {
            return Smt::ite($present, $value, $right);
        }
        if ($right->sort->equals($nullable)) {
            $this->encoding->registerSort($nullable);

            return Smt::ite($present, Smt::some($value), $right);
        }

        throw $this->reject($expr, sprintf(
            'The right side of ?? is a %s but the left side is a %s.',
            $right->sort->describe(),
            $inner->describe(),
        ));
    }

    /**
     * Whether `$list[$i]` is set, which is the key existing and the element not being `null`,
     * and the element itself without a safety obligation: `isset()` and `??` do not warn.
     *
     * @param array<string, Term> $env
     * @return array{Term, Term} the condition, and the element unwrapped when it is nullable
     */
    private function listPresence(Expr\ArrayDimFetch $read, Term $list, array $env, Term $pc): array
    {
        $index = $this->listIndex($read, $env, $pc);
        $element = $this->encoding->define('elem', Smt::listItem($list, $index));
        $exists = Smt::hasKey($list, $index);

        if ($element->sort->isNullable() && $element->sort->inner !== null) {
            return [
                $this->encoding->define('present', Smt::all($exists, Smt::not(Smt::isNone($element)))),
                Smt::unwrap($element),
            ];
        }

        return [$this->encoding->define('present', $exists), $element];
    }

    /**
     * The index of a list read, which PHP would otherwise convert to a key: only an int is one.
     *
     * @param array<string, Term> $env
     */
    private function listIndex(Expr\ArrayDimFetch $read, array $env, Term $pc): Term
    {
        $dim = $read->dim ?? throw $this->reject($read, 'Cannot use [] for reading.');
        $index = $this->translate($dim, $env, $pc);
        if (!$index->sort->isInt()) {
            throw $this->reject($dim, sprintf(
                'The index of a list must be an int, not a %s: PHP converts some strings and floats to int keys, which is not modelled. Use an int index; for a float, check it and cast it with (int).',
                $index->sort->describe(),
            ));
        }

        return $this->encoding->define('idx', $index);
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
            foreach ($arm->conds as $condition) {
                $candidate = $this->translate($condition, $remainingEnv, $remaining);
                $matches[] = $this->identical($expr, $subject, $candidate);
            }
            // The arm runs when any of its conditions holds, so only a single condition
            // tells the body something; several are a disjunction, like `||`.
            $armEnv = $matchesTrue && count($arm->conds) === 1
                ? $this->narrow($arm->conds[0], true, $remainingEnv)
                : $remainingEnv;
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
        foreach ([$first, $second] as $branch) {
            if ($branch->sort->containsNull() && !$branch->sort->isNull()) {
                throw $this->reject($at, sprintf('A %s with a null value cannot be chosen between branches: return it directly, or build it from values that have the type it will hold.', $branch->sort->describe()));
            }
        }

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

        // `[]` has no element type of its own: it takes the one of the other branch.
        if ($first->sort->isEmptyList() || $second->sort->isEmptyList()) {
            if ($first->sort->isEmptyList() && $this->canCoerce($first->sort, $second->sort)) {
                return [$this->coerce($at, $first, $second->sort), $second];
            }
            if ($second->sort->isEmptyList() && $this->canCoerce($second->sort, $first->sort)) {
                return [$first, $this->coerce($at, $second, $first->sort)];
            }

            throw $this->reject($at, sprintf(
                'Branches have different types (%s and %s).',
                $first->sort->describe(),
                $second->sort->describe(),
            ));
        }

        if ($first->sort->isNull() && !$second->sort->isNull()) {
            $target = $second->sort->isNullable() ? $second->sort : Sort::nullable($second->sort);

            return [$this->none($target), $this->coerce($at, $second, $target)];
        }
        if ($second->sort->isNull() && !$first->sort->isNull()) {
            $target = $first->sort->isNullable() ? $first->sort : Sort::nullable($first->sort);

            return [$this->coerce($at, $first, $target), $this->none($target)];
        }
        if ($first->sort->isNullable() && $first->sort->inner?->equals($second->sort) === true) {
            return [$first, $this->some($second)];
        }
        if ($second->sort->isNullable() && $second->sort->inner?->equals($first->sort) === true) {
            return [$this->some($first), $second];
        }

        throw $this->reject($at, sprintf(
            'Branches have different types (%s and %s).',
            $first->sort->describe(),
            $second->sort->describe(),
        ));
    }

    /**
     * A nullable value built from a literal, whose sort no declaration has registered yet.
     */
    private function none(Sort $nullable): Term
    {
        $this->encoding->registerSort($nullable);

        return Smt::none($nullable);
    }

    private function some(Term $value): Term
    {
        $wrapped = Smt::some($value);
        $this->encoding->registerSort($wrapped->sort);

        return $wrapped;
    }

    private function unreached(Term $like): Term
    {
        return $like->sort->isNull() ? $like : $this->encoding->fresh('unreached', $like->sort);
    }

    /**
     * `throw new E(...)` or `throw E::factory(...)`. The path ends here, which the statement translator applies,
     * and the throw must be allowed by a `#[Throws]` on that path.
     *
     * @param array<string, Term> $env
     */
    private function throwExpression(Expr\Throw_ $expr, array $env, Term $pc, bool $tail): Term
    {
        if ($this->throws === null) {
            throw $this->reject($expr, match (true) {
                $this->isContract => 'throw cannot appear in a contract.',
                default => 'throw is not supported here.',
            });
        }
        if (!$tail) {
            throw $this->reject($expr, 'throw is only supported as a statement, or as a whole branch of match, ?: or ?? whose value is returned or assigned.');
        }

        $new = $expr->expr;
        if ($new instanceof StaticCall) {
            return $this->throwFromFactory($expr, $new, $env, $pc);
        }
        if (!$new instanceof New_) {
            throw $this->reject($expr, match (true) {
                $new instanceof Expr\MethodCall, $new instanceof Expr\NullsafeMethodCall => 'throw of the result of a method call is not supported: the class of the object is not known here. Use a static factory, `throw Exception::factory(...)`, or `throw new Exception(...)`.',
                default => 'Only `throw new Exception(...)` and `throw Exception::factory(...)` with a static factory are supported: the exception must be built where it is thrown, not taken from a variable or another expression.',
            });
        }
        if (!$new->class instanceof Name) {
            throw $this->reject($expr, 'The class of a thrown exception must be written by name: `new $class(...)` and anonymous classes are not supported.');
        }

        $className = $this->classes->resolve($new->class, $this->lineOf($expr));
        if (!$this->lookups->hasClass($className)) {
            throw $this->reject($expr, sprintf('Class %s cannot be resolved.', $className));
        }
        $class = $this->lookups->getClass($className);
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

        $this->obligate(ObligationKind::UnexpectedThrow, $expr, $pc, $this->throws->allowed($class), $this->throws->describeAllowed($class));
        $this->throws->record($pc);

        return Smt::never();
    }

    /**
     * `throw Exception::factory(...)`. The factory is a static method whose native return
     * type is the exception class. Its body is never read: the proof takes it that it
     * returns an instance of that class without throwing, and matches that class against
     * the `#[Throws]` declarations.
     *
     * @param array<string, Term> $env
     */
    private function throwFromFactory(Expr\Throw_ $expr, StaticCall $call, array $env, Term $pc): Term
    {
        if (!$call->class instanceof Name || !$call->name instanceof Identifier) {
            throw $this->reject($expr, 'The class and the method of an exception factory must be written by name: `throw $class::make()` and `throw Exception::$method()` are not supported.');
        }
        if ($call->isFirstClassCallable()) {
            throw $this->reject($expr, 'First-class callable syntax builds a Closure, which cannot be thrown.');
        }
        $inliner = $this->requireInliner($call);
        $throws = $this->throws ?? throw new LogicException('A throw is only translated with the #[Throws] declarations.');

        $line = $this->lineOf($expr);
        $className = $this->classes->resolve($call->class, $line);
        if (!$this->lookups->hasClass($className)) {
            throw $this->reject($expr, sprintf('Class %s cannot be resolved.', $className));
        }
        $class = $this->lookups->getClass($className);
        $name = $call->name->toString();
        if (!$class->hasNativeMethod($name)) {
            throw $this->reject($expr, sprintf('%s has no method %s().', $class->getDisplayName(), $name));
        }

        $method = $class->getNativeMethod($name);
        $label = sprintf('%s::%s()', $method->getDeclaringClass()->getDisplayName(), $method->getName());
        if (!$method->isStatic()) {
            throw $this->reject($expr, sprintf('%s is not a static method, so it needs an object that is not known here. Make it static, or build the exception with `new`.', $label));
        }
        if ($method->isAbstract()) {
            throw $this->reject($expr, sprintf('%s is abstract, so the body that runs is not known here. Call a concrete factory, or build the exception with `new`.', $label));
        }
        // PHPStan reports an inaccessible method too, but a proof must not rest on a call that throws.
        if (!$this->canAccess($method->getDeclaringClass(), $method->isPrivate(), $method->isPublic())) {
            throw $this->reject($expr, sprintf('%s is not accessible here.', $label));
        }

        // Only the native type is enforced by PHP: a PHPDoc `@return` is not a guarantee.
        $returned = $method->getOnlyVariant()->getNativeReturnType();
        $thrown = match (true) {
            $returned instanceof StaticType => $class,
            $returned->isObject()->yes() && count($returned->getObjectClassReflections()) === 1 => $returned->getObjectClassReflections()[0],
            default => null,
        };
        if ($thrown === null) {
            throw $this->reject($expr, sprintf(
                '%s returns %s, which is not a single exception class. Declare the exception class, `self` or `static` as its native return type (an interface, a union, a nullable type, `never` and a missing type are not supported).',
                $label,
                $returned instanceof MixedType && !$returned->isExplicitMixed() ? 'no declared type' : $returned->describe(VerbosityLevel::typeOnly()),
            ));
        }
        if ($thrown->isInterface() || !$thrown->implementsInterface(\Throwable::class)) {
            throw $this->reject($expr, sprintf(
                '%s returns %s, which is not an exception class: declare a class that implements Throwable as its native return type.',
                $label,
                $thrown->getDisplayName(),
            ));
        }
        $inheritor = $thrown->isFinalByKeyword() ? null : $throws->conditionOnASubclassOf($thrown, $this->lookups);
        if ($inheritor !== null) {
            throw $this->reject($expr, sprintf(
                '%s returns %s, which is not final: the object can be an instance of a subclass, and a #[Throws] with a `when` condition on %s depends on the class. Make %s final, or declare a final class as the return type.',
                $label,
                $thrown->getDisplayName(),
                $inheritor,
                $thrown->getDisplayName(),
            ));
        }

        $inliner->bindFactoryArguments($this, $call, $method, $call->args, $env, $pc, $line);


        $this->obligate(ObligationKind::UnexpectedThrow, $expr, $pc, $throws->allowed($thrown), $throws->describeAllowed($thrown));
        $throws->record($pc);

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
        // implies() is not a PHP function: it only exists in contracts, which PHP never runs.
        if ($name !== 'implies' && !$this->isGlobalFunction($call->name, $name)) {
            throw $this->reject($call, sprintf(
                'Call to %s\\%s() is not part of the verified subset: purity cannot be established.',
                $this->classes->namespace(),
                $call->name->toString(),
            ));
        }
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
        if ($name === 'count') {
            return $this->count($call, $arguments, $env, $pc);
        }
        if ($name === 'array_sum') {
            return $this->arraySum($call, $arguments, $env, $pc);
        }
        if ($this->isArrayFunction($name) || (in_array($name, ['min', 'max'], true) && count($arguments) === 1)) {
            throw $this->reject($call, sprintf('Call to %s() is not part of the verified subset: of the array functions, only count(), in_array() and array_sum() are supported. Visit the elements with foreach instead.', $name));
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
     * Whether a call resolves to the global function: an unqualified name in a namespace
     * calls the function of that namespace when there is one, as PHP does at runtime.
     */
    private function isGlobalFunction(Name $name, string $function): bool
    {
        $namespace = $this->classes->namespace();
        if ($name->isFullyQualified() || $namespace === null || $namespace === '') {
            return true;
        }

        return !$this->lookups->hasFunction(new Name\FullyQualified($namespace . '\\' . $function), null);
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
        if (!$this->isConstant($divisor)) {
            $this->encoding->noteNonLinear('division', $this->printer->prettyPrintExpr($call));
        }

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

        $needle = $this->translate($slots['needle'], $env, $pc);
        if ($needle->sort->containsNull() && !$needle->sort->isNull()) {
            throw $this->reject($call, sprintf('The needle of in_array() cannot be a %s with a null value inside it.', $needle->sort->describe()));
        }
        $needle = $this->encoding->define('needle', $needle);
        if (!$strict && !in_array($needle->sort->kind, [SortKind::Int, SortKind::Float, SortKind::Bool, SortKind::Enum], true)) {
            throw $this->reject($call, sprintf(
                'in_array() without strict: true compares with ==, which juggles a %s; pass true as the third argument.',
                $needle->sort->describe(),
            ));
        }

        if (!$slots['haystack'] instanceof Expr\Array_ && !$slots['haystack'] instanceof ClassConstFetch) {
            return $this->inArrayOfList($call, $needle, $strict, $this->translate($slots['haystack'], $env, $pc));
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
     * `in_array($needle, $list, $strict)` where `$list` is a list-valued expression: some key
     * below `count($list)` whose element equals the needle, with `===` or `==` as PHP does it
     * element by element (IEEE equality for floats, so NAN is never found and -0.0 equals 0.0).
     * The needle must have the element sort, once a nullable is lifted, so that `==` between
     * the two is as exact as `===`.
     */
    private function inArrayOfList(FuncCall $call, Term $needle, bool $strict, Term $list): Term
    {
        if ($list->sort->isShape()) {
            throw $this->reject($call, 'in_array() is only supported on a list or a list literal, not on an array shape; compare the values, such as $x === $totals[\'net\'] || $x === $totals[\'vat\'].');
        }
        if ($list->sort->isNullable() && $list->sort->inner?->isList() === true) {
            throw $this->reject($call, sprintf('in_array() needs a list, not a %s, which may be null here: PHP throws a TypeError for in_array() on null. Check it first with `!== null`.', $list->sort->describe()));
        }
        if ($list->sort->isEmptyList()) {
            return Smt::bool(false);
        }
        $element = $list->sort->element;
        if (!$list->sort->isList() || $element === null) {
            throw $this->reject($call, self::IN_ARRAY_HAYSTACK);
        }

        $plain = $element->isNullable() && $element->inner !== null ? $element->inner : $element;
        if ($plain->kind === SortKind::Record || $plain->isShape()) {
            throw $this->reject($call, sprintf(
                'in_array() on a list of %s compares them by identity with strict: true and property by property without it, which is not modelled; compare a field instead.',
                $plain->isShape() ? 'array shapes' : 'objects',
            ));
        }
        if (!$strict && $element->isNullable()) {
            throw $this->reject($call, sprintf(
                'in_array() without strict: true compares with ==, which juggles a %s (null == 0 is true); pass true as the third argument.',
                $element->describe(),
            ));
        }

        $list = $this->encoding->define('list', $list);
        $matches = match (true) {
            $needle->sort->equals($element) => static fn (Term $item): Term => Smt::phpEquals($item, $needle),
            $element->isNullable() && $needle->sort->isNull() => static fn (Term $item): Term => Smt::isNone($item),
            $element->isNullable() && $element->inner !== null && $needle->sort->equals($element->inner) => static fn (Term $item): Term => Smt::phpEquals($item, Smt::some($needle)),
            $needle->sort->isNullable() && $needle->sort->inner !== null && $needle->sort->inner->equals($element) => static fn (Term $item): Term => Smt::phpEquals(Smt::some($item), $needle),
            default => throw $this->reject($call, sprintf(
                'in_array() needs a needle of the element type of the list: got %s for a %s. Convert the needle first, such as (float) $x.',
                $needle->sort->describe(),
                $list->sort->describe(),
            )),
        };

        return Smt::listContains($list, $matches);
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
            throw $this->reject($list, self::IN_ARRAY_HAYSTACK);
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
                $refusal = $this->enums->guard()->caseRefusal($this->lookups->getClass($enum->className), $expr->name->toString());
                if ($refusal !== null) {
                    throw $this->reject($expr, $refusal);
                }

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

        if (!$this->lookups->hasClass($className)) {
            throw $this->reject($expr, sprintf('Class %s cannot be resolved.', $className));
        }
        $class = $this->lookups->getClass($className);
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
        $refusal = $enum !== null && $class->hasEnumCase($name)
            ? $this->enums->guard()->caseRefusal($class, $name)
            : $this->enums->guard()->constantRefusal($constant);
        if ($refusal !== null) {
            throw $this->reject($expr, $refusal);
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
    private function staticCall(StaticCall $expr, array $env, Term $pc, bool $tail): Term
    {
        if (!$expr->class instanceof Name || !$expr->name instanceof Identifier) {
            throw $this->reject($expr, 'Dynamic static calls are forbidden in verified methods.');
        }

        $method = strtolower($expr->name->toString());
        $enum = $method === 'from' || $method === 'tryfrom'
            ? $this->enums->find($this->classes->resolveStatically($expr->class, $this->lineOf($expr)))
            : null;

        // A pure enum has no built-in from(), but may declare a static method of that name.
        if ($enum !== null && !$enum->backed) {
            if (!$this->lookups->getClass($enum->className)->hasNativeMethod($expr->name->toString())) {
                throw $this->reject($expr, sprintf('%s is not a backed enum, so it has no %s().', $enum->shortName(), $expr->name->toString()));
            }
            $enum = null;
        }

        if ($enum === null) {
            return $this->inline(
                $expr,
                $this->classes->resolveStatically($expr->class, $this->lineOf($expr)),
                $expr->name->toString(),
                null,
                $this->classes->isLateStatic($expr->class),
                $env,
                $pc,
                $tail,
            );
        }
        if ($enum->backingType === null) {
            throw $this->reject($expr, sprintf('%s::%s() is not supported: %s', $enum->shortName(), $expr->name->toString(), $enum->unmodelledBacking));
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
    private function methodCall(Expr\MethodCall $expr, array $env, Term $pc, bool $tail): Term
    {
        if (!$expr->name instanceof Identifier) {
            throw $this->reject($expr, 'Dynamic method calls are forbidden in verified methods.');
        }
        $this->requireInliner($expr);

        if ($this->statelessThis !== null && $expr->var instanceof Variable && $expr->var->name === 'this') {
            return $this->inline($expr, $this->statelessThis, $expr->name->toString(), null, false, $env, $pc, $tail, $this->statelessThis);
        }

        $receiver = $this->translate($expr->var, $env, $pc);
        $className = match (true) {
            $receiver->sort->enum !== null => $receiver->sort->enum->className,
            $receiver->sort->record !== null => $receiver->sort->record->className,
            $receiver->sort->isNullable() => throw $this->reject($expr, sprintf('$%s may be null here; check it first.', $this->describeSubject($expr->var))),
            default => throw $this->reject($expr, sprintf('Method calls are only supported on enums and data classes, not on a %s.', $receiver->sort->describe())),
        };

        return $this->inline($expr, $className, $expr->name->toString(), $this->encoding->define('recv', $receiver), false, $env, $pc, $tail);
    }

    /**
     * @param array<string, Term> $env
     * @param bool $tail whether the call is in tail position, the only place where it may throw
     */
    private function inline(Expr\MethodCall|StaticCall $call, string $className, string $method, ?Term $receiver, bool $lateStatic, array $env, Term $pc, bool $tail, ?string $statelessThis = null): Term
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
            $lateStatic,
            $call->args,
            $env,
            $pc,
            $tail,
            $statelessThis,
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
            $this->rejectShadowedProperty($expr, $object->sort->record->className, $property, '->');

            return Smt::field($object, $property);
        }

        if ($object->sort->isNullable()) {
            throw $this->reject($expr, sprintf('$%s may be null here; check it first or use ?->.', $this->describeSubject($expr->var)));
        }
        if ($object->sort->isShape()) {
            throw $this->reject($expr, sprintf('An array shape is read with [\'%1$s\'], not ->%1$s.', $property));
        }

        throw $this->reject($expr, sprintf('Property access ->%s is not supported on a %s.', $property, $object->sort->describe()));
    }

    /**
     * `$c->x` from a class S reads the private S::$x when $c is an instance of a subclass
     * of S, even if the subclass declares a public $x: PHP flags a property that redeclares
     * a private one, and then picks the private property of the calling scope
     * (zend_get_parent_private_property). The record models the subclass's property.
     */
    private function rejectShadowedProperty(PropertyFetch|NullsafePropertyFetch $expr, string $className, string $property, string $operator): void
    {
        $scope = $this->classes->declaringClass();
        if ($scope === null || $scope->getName() === $className || !$this->lookups->hasClass($className)) {
            return;
        }
        $class = $this->lookups->getClass($className);
        $native = $scope->getNativeReflection();
        if (!$class->isSubclassOfClass($scope) || !$native->hasProperty($property)) {
            return;
        }

        $declared = $native->getProperty($property);
        if (!$declared->isPrivate() || $declared->getDeclaringClass()->getName() !== $scope->getName()) {
            return;
        }

        throw $this->reject($expr, sprintf(
            'From %1$s, $%2$s%3$s%4$s reads the private property %1$s::$%4$s, not %5$s::$%4$s: PHP picks the private property of the calling class. Rename one of the two properties.',
            $scope->getDisplayName(),
            $this->describeSubject($expr->var),
            $operator,
            $property,
            $class->getDisplayName(),
        ));
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

        throw $this->reject($at, match (true) {
            $property === 'value' && $enum->backed => sprintf('->value of %s is not supported: %s', $enum->shortName(), $enum->unmodelledBacking),
            $property === 'value' => sprintf('%s is not a backed enum, so it has no ->value.', $enum->shortName()),
            default => sprintf('Property ->%s is not available on enum %s.', $property, $enum->shortName()),
        });
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
        $this->rejectShadowedProperty($expr, $record->record->className, $property, '?->');

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
        $refusal = $this->types->constructorBodyRefusal($record->className);
        if ($refusal !== null) {
            throw $this->reject($expr, sprintf('Cannot instantiate %s: %s.', $record->shortName(), $refusal));
        }

        $class = $this->lookups->getClass($record->className);
        $constructor = $class->getConstructor();
        // PHPStan reports an inaccessible constructor too, but a proof must not rest on code that throws.
        if (!$this->canAccess($constructor->getDeclaringClass(), $constructor->isPrivate(), $constructor->isPublic())) {
            throw $this->reject($expr, sprintf('Cannot instantiate %s: its constructor is not accessible here.', $record->shortName()));
        }
        if (ConstantGuard::hasDeprecatedAttribute($constructor->getAttributes())) {
            throw $this->reject($expr, sprintf(
                'Cannot instantiate %s: its constructor is #[\Deprecated], and calling it triggers E_USER_DEPRECATED, which runs the error handler.',
                $record->shortName(),
            ));
        }
        // The object built here is destroyed at the end of the statement, or later.
        if ($class->hasNativeMethod('__destruct')) {
            throw $this->reject($expr, sprintf('Cannot instantiate %s: its destructor runs code that is not verified.', $record->shortName()));
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
     * PHP reads the whole operand of `??` and `isset()` without a warning, so a list
     * element in the middle of the chain (`$lines[$i]['net'] ?? 0`) is quietly missing
     * rather than a read of a missing key. Only the last link is modelled.
     *
     * @param array<string, Term> $env
     */
    private function rejectQuietListChain(Expr $operand, array $env, Term $pc, string $construct): void
    {
        $node = $operand instanceof Expr\ArrayDimFetch || $operand instanceof PropertyFetch || $operand instanceof NullsafePropertyFetch
            ? $operand->var
            : null;
        while ($node instanceof Expr\ArrayDimFetch || $node instanceof PropertyFetch || $node instanceof NullsafePropertyFetch) {
            if ($node instanceof Expr\ArrayDimFetch && $node->dim !== null && $this->translate($node->var, $env, $pc)->sort->isList()) {
                throw $this->reject($operand, sprintf(
                    '%s on `%s` reads through the list element `%s`, which is not modelled: PHP treats a missing element in the middle of the chain as unset. Check `isset(%s)` first, or read `%s` into a variable.',
                    $construct,
                    $this->printer->prettyPrintExpr($operand),
                    $this->printer->prettyPrintExpr($node),
                    $this->printer->prettyPrintExpr($node),
                    $this->printer->prettyPrintExpr($node),
                ));
            }
            $node = $node->var;
        }
    }
    /**
     * @param array<string, Term> $env
     */
    private function issetExpression(Isset_ $expr, array $env, Term $pc): Term
    {
        $checks = [];
        foreach ($expr->vars as $variable) {
            $this->rejectQuietListChain($variable, $env, $pc, 'isset()');
            $read = $variable instanceof Expr\ArrayDimFetch && $variable->dim !== null ? $variable : null;
            $base = $read === null ? null : $this->translate($read->var, $env, $pc);
            if ($read !== null && $base !== null && $base->sort->isList()) {
                $checks[] = $this->listPresence($read, $base, $env, $pc)[0];
                continue;
            }

            $term = $read !== null && $base !== null
                ? $this->arrayDimFetch($read, $env, $pc, $base)
                : $this->translate($variable, $env, $pc);
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

    /**
     * Whether a term is an integer literal, so that a product or a division by it stays linear.
     */
    private function isConstant(Term $term): bool
    {
        return $term->atomic && preg_match('/^(\d+|\(- \d+\))$/', $term->smt) === 1;
    }

    private function requireNonZero(Expr $at, Term $divisor, Term $pc): void
    {
        $this->obligate(ObligationKind::DivisionByZero, $at, $pc, Smt::not(Smt::eq($divisor, Smt::int(0))));
    }

    private function obligate(ObligationKind $kind, Expr $at, Term $pc, Term $goal, ?string $allowedWhere = null, ?string $guard = null): void
    {
        $this->encoding->addObligation(new Obligation(
            $kind,
            $this->lineOf($at),
            $this->printer->prettyPrintExpr($at),
            $pc,
            $goal,
            $this->calls,
            $allowedWhere,
            $guard,
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

    /**
     * `['net' => $net, 'vat' => $vat]`: an array shape. The value of each key is translated
     * in the order written, and the fields are laid out in the canonical order of the sort,
     * which is not observable through any construct accepted here.
     *
     * @param array<string, Term> $env
     */
    private function arrayLiteral(Expr\Array_ $expr, array $env, Term $pc): Term
    {
        $keyed = 0;
        $spreads = false;
        foreach ($expr->items as $item) {
            if ($item->byRef) {
                throw $this->reject($expr, 'References in array literals are not supported: list the values, such as [$a, $b].');
            }
            $spreads = $spreads || $item->unpack;
            if ($item->key !== null) {
                ++$keyed;
            }
        }
        if ($spreads) {
            if ($keyed > 0) {
                throw $this->reject($expr, 'A spread is only supported in a list literal without keys, such as [...$items, $x]: PHP keeps the string keys of a spread array, which this literal would mix with the keys written.');
            }

            return $this->spreadLiteral($expr, $env, $pc);
        }
        if ($keyed > 0 && $keyed < count($expr->items)) {
            throw $this->reject($expr, 'An array literal is either a list without keys, such as [1, 2], or a shape with string keys, such as [\'net\' => 1]; mixing the two is not supported.');
        }
        if ($keyed === 0) {
            return $this->listLiteral($expr, $env, $pc);
        }

        $keys = [];
        foreach ($expr->items as $item) {
            $key = $item->key;
            if ($key instanceof Int_ || ($key instanceof String_ && (string) (int) $key->value === $key->value)) {
                throw $this->reject($expr, 'Integer keys, and string keys that PHP converts to integers such as \'1\', are not supported in array literals: write a list without keys, or use non-numeric string keys.');
            }
            if (!$key instanceof String_) {
                throw $this->reject($expr, 'The keys of an array literal must be string literals, such as [\'net\' => $net].');
            }
            if (in_array($key->value, $keys, true)) {
                throw $this->reject($expr, sprintf('Key \'%s\' appears twice in this array literal; PHP keeps the last value. Write it once.', $key->value));
            }
            $keys[] = $key->value;
        }

        /** @var array<string, Term> $values */
        $values = [];
        foreach ($expr->items as $position => $item) {
            $value = $this->translate($item->value, $env, $pc);
            if ($value->sort->isNever()) {
                throw $this->reject($item->value, 'A value that always throws cannot be stored in an array literal; write a throw statement.');
            }
            $values[$keys[$position]] = $value;
        }

        $sorted = $values;
        uksort($sorted, static fn (int|string $a, int|string $b): int => strcmp((string) $a, (string) $b));
        $sort = Sort::shape(ShapeSort::of(array_map(static fn (Term $value): Sort => $value->sort, $sorted)), $keys);
        $term = Smt::shape($sort, array_values($sorted));

        // A `null` value has no datatype of its own: the literal stays a pair of terms until
        // coerce() gives it the type that makes the value `?T`.
        return $sort->containsNull() ? $term : $this->encoding->define('shape', $term);
    }

    /**
     * `[...$a, $x, ...$b]`: PHP renumbers the keys of a spread list from 0, so the literal is
     * exactly the list of the elements in order, whatever the next free key of the lists
     * spread. Only lists can be spread: the keys of any other array are kept.
     *
     * @param array<string, Term> $env
     */
    private function spreadLiteral(Expr\Array_ $expr, array $env, Term $pc): Term
    {
        $segments = [];
        $element = null;
        foreach ($expr->items as $item) {
            $value = $this->translate($item->value, $env, $pc);
            if ($value->sort->isNever()) {
                throw $this->reject($item->value, 'A value that always throws cannot be stored in an array literal; write a throw statement.');
            }
            if ($item->unpack) {
                if ($value->sort->isList()) {
                    if ($element !== null && !$element->equals($value->sort->element ?? $element)) {
                        throw $this->reject($expr, sprintf('The lists spread here have different element types (%s and %s); a list must hold a single type.', $element->describe(), $value->sort->element?->describe() ?? '?'));
                    }
                    $element = $value->sort->element;
                } elseif (!$value->sort->isEmptyList()) {
                    throw $this->reject($item->value, sprintf(
                        'Only a list<T> can be spread in a list literal, not `%s` (%s): PHP keeps the keys of other arrays, which are not 0..n-1. Spread a list, or build the array with its keys.',
                        $this->printer->prettyPrintExpr($item->value),
                        $value->sort->describe(),
                    ));
                }
            } elseif ($value->sort->isList() || $value->sort->isEmptyList() || ($value->sort->isNullable() && $value->sort->inner?->isList() === true)) {
                throw $this->reject($expr, 'A list of lists is not supported yet: nested lists are not modelled. Use a list of data classes or of array shapes.');
            } elseif ($value->sort->containsNull() && !$value->sort->isNull()) {
                throw $this->reject($item->value, sprintf('A %s with a null value cannot be an element of a list: build it from values that have the type it will hold.', $value->sort->describe()));
            }
            $segments[] = [$value, $item->unpack];
        }
        if ($element === null) {
            throw $this->reject($expr, 'Spreading only [] gives no element type; spread a list<T>, such as [...$items, $x].');
        }

        $list = Sort::list($element);
        $this->encoding->registerSort($element);
        $result = $firstSpread = null;
        foreach ($segments as [$value, $spread]) {
            if ($spread) {
                if ($value->sort->isEmptyList()) {
                    continue;
                }
                if ($result === null) {
                    $result = $firstSpread = $value;
                } else {
                    $result = ListConcat::concat($this->encoding, $result, $value);
                }
                continue;
            }
            if (!$this->canCoerce($value->sort, $element)) {
                throw $this->reject($expr, sprintf(
                    'The elements of this list have different types (%s and %s); a list must hold a single type. Convert the elements first, such as (float) $x.',
                    $element->describe(),
                    $value->sort->describe(),
                ));
            }
            $single = $this->coerce($expr, $value, $element);
            $result = $this->encoding->define('list', $result === null
                ? Smt::listLiteral($list, [$single], $this->encoding->fresh('base', $list))
                : Smt::listAppend($result, $single));
        }

        if ($result === null) {
            throw new LogicException('A spread literal has at least one list.');
        }
        if ($result->smt === $firstSpread?->smt) {
            // `[...$a]` is a new array: it is not the value of `$a`, whose next free key may differ.
            $copy = $this->encoding->fresh('list', $list);
            $this->encoding->assert(Smt::eq($copy, $result));

            return $copy;
        }

        return $result;
    }
    /**
     * `[a, b]`, a list whose elements have one type (`null` makes it nullable), and `[]`, which
     * has no element type until it meets the one of a variable, a return type or the other
     * branch of a conditional.
     *
     * @param array<string, Term> $env
     */
    private function listLiteral(Expr\Array_ $expr, array $env, Term $pc): Term
    {
        if ($expr->items === []) {
            return Smt::symbol('empty_list', Sort::emptyList());
        }

        $values = [];
        foreach ($expr->items as $item) {
            $value = $this->translate($item->value, $env, $pc);
            if ($value->sort->isNever()) {
                throw $this->reject($item->value, 'A value that always throws cannot be stored in an array literal; write a throw statement.');
            }
            if ($value->sort->isList() || $value->sort->isEmptyList() || ($value->sort->isNullable() && $value->sort->inner?->isList() === true)) {
                throw $this->reject($expr, 'A list of lists is not supported yet: nested lists are not modelled. Use a list of data classes or of array shapes.');
            }
            if ($value->sort->containsNull() && !$value->sort->isNull()) {
                throw $this->reject($item->value, sprintf('A %s with a null value cannot be an element of a list: build it from values that have the type it will hold.', $value->sort->describe()));
            }
            $values[] = $value;
        }

        $bare = [];
        $nullable = false;
        foreach ($values as $value) {
            if ($value->sort->isNull()) {
                $nullable = true;
                continue;
            }
            $nullable = $nullable || $value->sort->isNullable();
            $bare[] = $value->sort->isNullable() && $value->sort->inner !== null ? $value->sort->inner : $value->sort;
        }
        if ($bare === []) {
            throw $this->reject($expr, 'A list holding only null has no element type; give the list a type by returning it, or use a value of the type it will hold.');
        }
        foreach ($bare as $sort) {
            if (!$sort->equals($bare[0])) {
                throw $this->reject($expr, sprintf(
                    'The elements of this list have different types (%s and %s); a list must hold a single type. Convert the elements first, such as (float) $x, or use an array shape with a key per value.',
                    $bare[0]->describe(),
                    $sort->describe(),
                ));
            }
        }

        $element = $nullable ? Sort::nullable($bare[0]) : $bare[0];
        $list = Sort::list($element);
        $base = $this->encoding->fresh('base', $list);
        $this->encoding->registerSort($element);

        return $this->encoding->define('list', Smt::listLiteral(
            $list,
            array_map(fn (Term $value): Term => $this->coerce($expr, $value, $element), $values),
            $base,
        ));
    }

    private function emptyListOf(Sort $list): Term
    {
        return $this->encoding->define('list', Smt::emptyListOf($list, $this->encoding->fresh('base', $list)));
    }

    /**
     * `$shape['net']`, with a string literal that is a key of the shape: the type requires
     * the key, so PHP raises no warning.
     *
     * @param array<string, Term> $env
     */
    private function arrayDimFetch(Expr\ArrayDimFetch $expr, array $env, Term $pc, ?Term $base = null): Term
    {
        if ($expr->dim === null) {
            throw $this->reject($expr, 'Cannot use [] for reading.');
        }

        $base ??= $this->translate($expr->var, $env, $pc);
        if ($base->sort->isEmptyList()) {
            throw $this->reject($expr, 'This list is always empty, so reading from it always reads a missing key; append to the list before reading it, or check count() first.');
        }
        if ($base->sort->isList()) {
            return $this->listRead($expr, $base, $env, $pc);
        }

        $shape = $base->sort->shape;
        if (!$base->sort->isShape() || $shape === null) {
            throw $this->reject($expr, match (true) {
                $base->sort->isString() => 'Reading [...] is only supported on lists and array shapes, not on a string; string offsets are not supported, use str_starts_with() or strlen().',
                $base->sort->isNullable() => sprintf('Reading [...] is only supported on lists and array shapes, not on a %s, which may be null here: check it first with `!== null`, `isset()` or `??`.', $base->sort->describe()),
                default => sprintf('Reading [...] is only supported on lists and array shapes, not on a %s.', $base->sort->describe()),
            });
        }
        if (!$expr->dim instanceof String_) {
            throw $this->reject($expr, 'The key of an array shape must be a string literal naming one of its keys, such as $totals[\'net\'].');
        }
        if (!$shape->has($expr->dim->value)) {
            $names = array_map(static fn (string $key): string => "'" . $key . "'", $shape->keys());
            $last = array_pop($names);

            throw $this->reject($expr, sprintf(
                "%s has no key '%s'; its keys are %s.",
                $base->sort->describe(),
                $expr->dim->value,
                $names === [] ? $last : implode(', ', $names) . ' and ' . $last,
            ));
        }

        return Smt::shapeField($base, $expr->dim->value);
    }

    /**
     * `$list[$i]`. PHP warns and yields null when the key does not exist, and a warning runs
     * the error handler, so the read is allowed only where the key provably exists.
     *
     * @param array<string, Term> $env
     */
    private function listRead(Expr\ArrayDimFetch $expr, Term $list, array $env, Term $pc): Term
    {
        $index = $this->listIndex($expr, $env, $pc);
        $subject = $this->printer->prettyPrintExpr($expr->var);
        $position = $this->printer->prettyPrintExpr($expr->dim ?? $expr);
        $guard = match (true) {
            $expr->dim instanceof Int_ && $expr->dim->value >= 0 => sprintf('count(%s) > %s', $subject, $position),
            default => sprintf(
                '%1$s >= 0 && %1$s < count(%2$s)',
                $expr->dim instanceof Variable || $expr->dim instanceof Int_ || $expr->dim instanceof PropertyFetch || $expr->dim instanceof Expr\ArrayDimFetch ? $position : '(' . $position . ')',
                $subject,
            ),
        };

        $this->obligate(ObligationKind::UndefinedKey, $expr, $pc, Smt::hasKey($list, $index), guard: $guard);

        return $this->encoding->define('elem', Smt::listItem($list, $index));
    }

    /**
     * Whether coerce() can give a value of one sort the other sort: nothing to do, `null`
     * or a `T` for a `?T`, `[]` for a list, or an array shape whose fields can each be coerced.
     */
    public function canCoerce(Sort $from, Sort $to): bool
    {
        if ($from->equals($to)) {
            return true;
        }
        if ($from->isEmptyList()) {
            return $to->isList() || ($to->isNullable() && $to->inner?->isList() === true);
        }
        if ($to->isNullable() && $to->inner !== null) {
            return $from->isNull() || $from->equals($to->inner) || ($from->isShape() && $this->canCoerce($from, $to->inner));
        }
        if ($from->isShape() && $to->isShape() && $from->shape !== null && $to->shape !== null) {
            if ($from->shape->keys() !== $to->shape->keys()) {
                return false;
            }
            foreach ($from->shape->fields as $key => $field) {
                if (!$this->canCoerce($field, $to->shape->fields[$key])) {
                    return false;
                }
            }

            return true;
        }

        return false;
    }

    /**
     * The same array under a type that is wider field by field: PHP does not touch the value.
     */
    private function rebuildShape(Node $at, Term $term, Sort $target): Term
    {
        $from = $term->sort->shape;
        $to = $target->shape;
        if ($from === null || $to === null) {
            throw $this->reject($at, sprintf('Cannot use a %s where a %s is expected.', $term->sort->describe(), $target->describe()));
        }

        $parts = [];
        foreach ($from->keys() as $position => $key) {
            $part = $term->components[$position] ?? Smt::shapeField($term, $key);
            $parts[] = $this->coerce($at, $part, $to->fields[$key]);
        }

        return $this->encoding->define('shape', Smt::shape($target, $parts));
    }

    private function shapeIdentityRefusal(): string
    {
        return 'Array shapes compared with === also compare the order of their keys and any extra keys, which the type array{...} does not fix; compare the elements instead, such as $a[\'net\'] === $b[\'net\'].';
    }

    /**
     * @param list<Expr> $arguments
     * @param array<string, Term> $env
     */
    private function count(FuncCall $call, array $arguments, array $env, Term $pc): Term
    {
        if (count($arguments) !== 1) {
            throw $this->reject($call, 'count() takes exactly one argument here; COUNT_RECURSIVE is not supported. Write count($list).');
        }

        $subject = $this->translate($arguments[0], $env, $pc);

        return match (true) {
            $subject->sort->isList() => Smt::listLength($subject),
            $subject->sort->isEmptyList() => Smt::int(0),
            $subject->sort->isShape() => throw $this->reject($call, 'count() of an array shape is not supported: the type array{...} does not rule out extra keys. count() is supported on lists.'),
            $subject->sort->isNullable() && $subject->sort->inner?->isList() === true => throw $this->reject($call, sprintf('count() needs a list, not a %s, which may be null here: PHP throws a TypeError for count(null). Check it first with `!== null`.', $subject->sort->describe())),
            default => throw $this->reject($call, sprintf('count() is only supported on lists, not on a %s.', $subject->sort->describe())),
        };
    }

    /**
     * `array_sum()` over a `list<int>`: PHP adds from `0` from left to right and turns the sum
     * into a float at the first prefix that leaves the int range. In the body that is an
     * overflow obligation on every prefix, so the term is the exact sum wherever the proof
     * goes on. In a contract it is the mathematical sum, like `+`.
     *
     * @param list<Expr> $arguments
     * @param array<string, Term> $env
     */
    private function arraySum(FuncCall $call, array $arguments, array $env, Term $pc): Term
    {
        if (count($arguments) !== 1) {
            throw $this->reject($call, 'array_sum() takes exactly one argument. Write array_sum($list).');
        }

        $subject = $this->translate($arguments[0], $env, $pc);
        if ($subject->sort->isEmptyList()) {
            return Smt::int(0);
        }
        if ($subject->sort->isNullable() && $subject->sort->inner?->isList() === true) {
            throw $this->reject($call, sprintf('array_sum() needs a list, not a %s, which may be null here: PHP throws a TypeError for array_sum(null). Check it first with `!== null`.', $subject->sort->describe()));
        }
        $element = $subject->sort->isList() ? $subject->sort->element : null;
        if ($element?->isFloat() === true) {
            throw $this->reject($call, 'array_sum() of a list<float> is not supported: it returns the int 0 for an empty list and a float otherwise. Add the elements in a foreach, starting from 0.0.');
        }
        if ($element === null || !$element->isInt()) {
            throw $this->reject($call, 'array_sum() is only supported on a list<int>: PHP converts null, bools and numeric strings to numbers, which is not modelled. Add the elements in a foreach.');
        }

        $this->encoding->requireListSum();
        $list = $this->encoding->define('list', $subject);
        if (!$this->isContract) {
            $this->obligate(ObligationKind::Overflow, $call, $pc, Smt::prefixSumsInRange($list));
        }

        return $this->encoding->define('sum', Smt::prefixSum($list, Smt::listLength($list)));
    }

    private function isArrayFunction(string $name): bool
    {
        return str_starts_with($name, 'array_') || in_array($name, [
            'sort', 'rsort', 'usort', 'uasort', 'uksort', 'asort', 'arsort', 'ksort', 'krsort', 'shuffle', 'range', 'compact',
            'extract', 'implode', 'join', 'explode', 'end', 'reset', 'next', 'prev', 'current', 'key', 'sizeof', 'key_exists',
            'is_array', 'is_list', 'iterator_to_array',
        ], true);
    }

    private function explainUnsupported(Expr $expr): string
    {
        return match (true) {
            $expr instanceof Expr\Assign, $expr instanceof Expr\AssignOp, $expr instanceof Expr\AssignRef
                => 'Assignments are only allowed as standalone statements on local variables.',
            $expr instanceof Expr\MethodCall, $expr instanceof Expr\NullsafeMethodCall
                => 'Method calls and instantiations are not part of the verified subset yet: side effects cannot be ruled out.',
            $expr instanceof Expr\Empty_
                => 'empty() relies on truthiness; write count($list) === 0.',
            $expr instanceof Cast
                => 'Only (int), (float) and (string) casts are supported.',
            $expr instanceof Expr\Closure, $expr instanceof Expr\ArrowFunction
                => 'Closures are not supported in verified methods.',
            $expr instanceof Expr\Include_, $expr instanceof Expr\Eval_, $expr instanceof Expr\Exit_, $expr instanceof Expr\Print_, $expr instanceof Expr\ShellExec
                => 'Side effects and dynamic code are forbidden in verified methods.',
            default => sprintf('Expression %s is not part of the verified subset.', $expr->getType()),
        };
    }
}
