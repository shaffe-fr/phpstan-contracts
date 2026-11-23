<?php

declare(strict_types=1);

namespace PhpstanContracts\Translate;

use PhpstanContracts\Smt\Smt;
use PhpstanContracts\Smt\Sort;
use PhpstanContracts\Smt\Term;
use PhpParser\Node;
use PhpParser\Node\Expr;
use PhpParser\Node\Expr\BinaryOp;
use PhpParser\Node\Expr\Variable;
use PhpParser\Node\Stmt;
use PhpParser\NodeTraverser;
use PhpParser\NodeVisitor;
use PhpParser\NodeVisitorAbstract;

/**
 * Symbolic execution of structured statements into SSA form.
 *
 * Each assignment defines a fresh SMT constant. At an `if`, both branches run
 * from the same state and the variables that differ are merged with `ite`,
 * which plays the role of a phi node. A `return` records (path, value) and
 * kills the path, so later statements only apply to the paths still alive.
 */
final class StatementTranslator
{
    private const LOCAL_WRITES_ONLY = 'Only assignments to local variables are allowed (no property, array or dynamic writes); appending is supported on a list built in the method ($list = []; $list[] = $value), or write $list = [...$list, $value].';

    /**
     * The SMT constants that hold a list built in this method: a literal, `[]`, a spread or an
     * append to such a list, and the copies and merges of those. Only the next free key of such a
     * list is known to be `count($list)`; PHP appends at the next free key of the array, which a
     * caller's `unset()` can leave past `count()` in a list received from outside.
     *
     * @var array<string, true>
     */
    private array $built = [];

    public function __construct(
        private readonly ExpressionTranslator $expressions,
        private readonly Encoding $encoding,
        private readonly Sort $resultSort,
        private readonly ?InlinedReturns $inlinedReturns = null,
    ) {
    }

    public const GENERATOR_REFUSAL = 'Generators are not supported: a yield anywhere in the body, even in unreachable code, makes the method return a Generator.';

    /**
     * Whether a `yield` makes the body a generator. PHP decides this when it compiles the
     * body, so the `yield` counts even where no path reaches it, which execute() skips.
     *
     * @param array<Stmt> $statements
     */
    public static function isGenerator(array $statements): bool
    {
        $traverser = new NodeTraverser();
        $visitor = new class extends NodeVisitorAbstract {
            public bool $found = false;

            public function enterNode(Node $node): ?int
            {
                // A closure or a class has a body of its own.
                if ($node instanceof Expr\Closure || $node instanceof Expr\ArrowFunction || $node instanceof Stmt\ClassLike || $node instanceof Stmt\Function_) {
                    return NodeVisitor::DONT_TRAVERSE_CHILDREN;
                }
                if ($node instanceof Expr\Yield_ || $node instanceof Expr\YieldFrom) {
                    $this->found = true;

                    return NodeVisitor::STOP_TRAVERSAL;
                }

                return null;
            }
        };
        $traverser->addVisitor($visitor);
        $traverser->traverse($statements);

        return $visitor->found;
    }

    /**
     * @param array<Stmt> $statements
     */
    public function execute(array $statements, State $state): State
    {
        foreach ($statements as $statement) {
            if ($state->reached->isFalse()) {
                break;
            }
            $state = $this->executeOne($statement, $state);
        }

        return $state;
    }

    private function executeOne(Stmt $statement, State $state): State
    {
        return match (true) {
            $statement instanceof Stmt\Expression => $this->executeExpression($statement->expr, $state),
            $statement instanceof Stmt\Return_ => $this->executeReturn($statement, $state),
            $statement instanceof Stmt\If_ => $this->executeIf(
                $statement,
                $statement->cond,
                $statement->stmts,
                $statement->elseifs,
                $statement->else,
                $state,
            ),
            $statement instanceof Stmt\Nop => $state,
            default => throw $this->expressions->reject($statement, $this->explainUnsupported($statement)),
        };
    }

    private function executeExpression(Expr $expr, State $state): State
    {
        if ($expr instanceof Expr\AssignOp\Plus) {
            return $this->assign($expr->var, new BinaryOp\Plus($expr->var, $expr->expr, $expr->getAttributes()), $state, $expr);
        }
        if ($expr instanceof Expr\AssignOp\Minus) {
            return $this->assign($expr->var, new BinaryOp\Minus($expr->var, $expr->expr, $expr->getAttributes()), $state, $expr);
        }
        if ($expr instanceof Expr\AssignOp\Mul) {
            return $this->assign($expr->var, new BinaryOp\Mul($expr->var, $expr->expr, $expr->getAttributes()), $state, $expr);
        }
        if ($expr instanceof Expr\AssignOp\Concat) {
            return $this->assign($expr->var, new BinaryOp\Concat($expr->var, $expr->expr, $expr->getAttributes()), $state, $expr);
        }
        if ($expr instanceof Expr\AssignOp\Coalesce) {
            return $this->assign($expr->var, new BinaryOp\Coalesce($expr->var, $expr->expr, $expr->getAttributes()), $state, $expr);
        }
        if ($expr instanceof Expr\Assign) {
            return $this->assign($expr->var, $expr->expr, $state, $expr);
        }
        if ($expr instanceof Expr\PreInc || $expr instanceof Expr\PostInc || $expr instanceof Expr\PreDec || $expr instanceof Expr\PostDec) {
            throw $this->expressions->reject($expr, 'Increment and decrement are not supported; write `$x += 1` or `$x -= 1`.');
        }
        if ($expr instanceof Expr\Throw_) {
            $this->expressions->translateTail($expr, $state->view(), $state->reached);
            $this->expressions->takeThrows();

            return $state->withReached(Smt::bool(false));
        }

        throw $this->expressions->reject(
            $expr,
            'Only assignments to local variables are allowed as statements; calls and other expressions may have side effects.',
        );
    }

    private function assign(Expr $target, Expr $value, State $state, Expr $at): State
    {
        if ($target instanceof Expr\Array_ || $target instanceof Expr\List_) {
            throw $this->expressions->reject($at, 'Destructuring ([$a, $b] = ...) is not supported: read the elements with $list[0] and $list[1], or with their key, such as $shape[\'net\'].');
        }
        if ($target instanceof Expr\ArrayDimFetch) {
            if ($at instanceof Expr\Assign && $target->dim === null && $target->var instanceof Variable && is_string($target->var->name) && $target->var->name !== 'this') {
                return $this->append($target->var->name, $value, $state, $at);
            }

            throw $this->expressions->reject($at, $this->arrayWriteRefusal($target, $state));
        }
        if (!$target instanceof Variable || !is_string($target->name) || $target->name === 'this') {
            throw $this->expressions->reject($at, self::LOCAL_WRITES_ONLY);
        }

        return $this->bind($target->name, $this->valueOf($value, $state, $at), $state, $at, $this->isBuilt($value, $state));
    }

    /**
     * Whether the value of an expression is a list built here.
     */
    private function isBuilt(Expr $value, State $state): bool
    {
        return match (true) {
            $value instanceof Expr\Array_ => true,
            $value instanceof Variable => is_string($value->name) && isset($state->view()[$value->name]) && $this->isBuiltTerm($state->view()[$value->name]),
            $value instanceof Expr\Ternary => $value->if !== null && $this->isBuilt($value->if, $state) && $this->isBuilt($value->else, $state),
            $value instanceof BinaryOp\Coalesce => $this->isBuilt($value->left, $state) && $this->isBuilt($value->right, $state),
            default => false,
        };
    }

    private function isBuiltTerm(Term $term): bool
    {
        return isset($this->built[$term->smt]);
    }

    /**
     * `$list[] = $value`: a copy of the list with one more element at the key `count($list)`.
     * Every version of a variable is an immutable term, so the copy-on-write of PHP is free:
     * what the variable held before, and any other variable that held the same list, is unchanged.
     */
    private function append(string $name, Expr $value, State $state, Expr $at): State
    {
        $list = $state->view()[$name] ?? null;
        if ($list === null) {
            throw $this->expressions->reject($at, sprintf('Appending to $%1$s, which is not defined at this point, would create an array; start with $%1$s = [].', $name));
        }

        $element = $this->valueOf($value, $state, $at);
        $appended = match (true) {
            $list->sort->isEmptyList() => $this->firstElement($name, $element, $at),
            $list->sort->isList() && !$this->isBuiltTerm($list) => throw $this->expressions->reject($at, sprintf(
                'Appending to $%1$s, a list received from outside the method, is not supported: PHP appends at the array\'s next free key, which can be past count($%1$s) after an unset() by the caller, so the result may not be a list. Write $%1$s = [...$%1$s, $value] instead, which always builds a list.',
                $name,
            )),
            $list->sort->isList() => $this->appendTo($list, $element, $at),
            $list->sort->isShape() => throw $this->expressions->reject($at, 'Appending to an array shape is not supported: build a new shape literal.'),
            $list->sort->isNullable() && $list->sort->inner?->isList() === true => throw $this->expressions->reject($at, sprintf('Appending to $%1$s, which may be null here, would create a list; check it first with `!== null`, or start with $%1$s = [].', $name)),
            default => throw $this->expressions->reject($at, sprintf('Appending to $%s is only supported on a list, not on a %s.', $name, $list->sort->describe())),
        };

        return $this->bind($name, $appended, $state, $at, true);
    }

    /**
     * `$list = []; $list[] = $x;`: the first element gives the list its element type.
     */
    private function firstElement(string $name, Term $element, Expr $at): Term
    {
        $sort = $element->sort;
        if ($sort->isNull() || $sort->isList() || $sort->isEmptyList() || ($sort->isNullable() && $sort->inner?->isList() === true) || $sort->containsNull()) {
            throw $this->expressions->reject($at, sprintf(
                'Cannot infer the element type of $%s from a %s: start the list with an element of the type it will hold, or return it directly. A list of lists is not supported yet.',
                $name,
                $sort->describe(),
            ));
        }

        $list = Sort::list($sort);

        return Smt::listLiteral($list, [$element], $this->encoding->fresh('base', $list));
    }

    private function appendTo(Term $list, Term $element, Expr $at): Term
    {
        $expected = $list->sort->element;
        if ($expected === null || $element->sort->isList() || $element->sort->isEmptyList() || !$this->expressions->canCoerce($element->sort, $expected)) {
            throw $this->expressions->reject($at, sprintf(
                'Appending a %1$s to a %2$s would make a list of %3$s|%1$s, which is not supported; convert the value first, such as (float) $x.',
                $element->sort->describe(),
                $list->sort->describe(),
                $expected?->describe() ?? '?',
            ));
        }

        return Smt::listAppend($list, $this->expressions->coerce($at, $element, $expected));
    }

    /**
     * Gives a local variable its new value.
     */
    private function bind(string $name, Term $term, State $state, Expr $at, bool $built = false): State
    {
        $previous = $state->variables[$name] ?? null;

        // An array that holds `null` has no type of its own: it needs the type of the variable it is assigned to.
        if ($term->sort->containsNull() && !$term->sort->isNull()) {
            if ($previous === null) {
                throw $this->expressions->reject($at, sprintf('Cannot infer a type for $%s from a %s: a null inside an array has no type of its own; assign it to a variable that already has the type it will hold, or return it directly.', $name, $term->sort->describe()));
            }
            $term = $this->expressions->coerce($at, $term, $previous->sort->isNullable() && $previous->sort->inner !== null ? $previous->sort->inner : $previous->sort);
        }

        if ($previous === null && $term->sort->isNull()) {
            throw $this->expressions->reject($at, sprintf('Cannot infer a type for $%s from null; assign a value of the type it will hold, or give a nullable parameter.', $name));
        }

        // `[]` has no type until it meets the list it is assigned to, or gives the variable that holds it one.
        $inner = $previous !== null && $previous->sort->isNullable() ? $previous->sort->inner : null;
        if ($previous !== null && $term->sort->isEmptyList() && $this->expressions->canCoerce($term->sort, $inner ?? $previous->sort)) {
            $term = $this->expressions->coerce($at, $term, $inner ?? $previous->sort);
        }

        $stored = $term;
        if ($previous !== null && !$previous->sort->equals($term->sort) && !($previous->sort->isEmptyList() && $term->sort->isList())) {
            if (!$previous->sort->isNullable()) {
                throw $this->expressions->reject($at, sprintf(
                    'Variable $%s changes type from %s to %s.',
                    $name,
                    $previous->sort->describe(),
                    $term->sort->describe(),
                ));
            }
            $stored = $this->expressions->coerce($at, $term, $previous->sort);
        }

        $narrowedValue = $previous !== null && $previous->sort->isNullable() && !$term->sort->isNullable() && !$term->sort->isNull()
            ? $this->encoding->define($name . '_value', $term)
            : null;
        $defined = $this->encoding->define($name, $stored);
        if ($built) {
            $this->built[$defined->smt] = true;
            if ($narrowedValue !== null) {
                $this->built[$narrowedValue->smt] = true;
            }
        }

        return $this->stopThrownPaths($state->withVariable($name, $defined, $narrowedValue));
    }

    /**
     * The value of an assignment or a `return`, where a `throw` may end some paths.
     */
    private function valueOf(Expr $value, State $state, Node $at): Term
    {
        $term = $this->expressions->translateTail($value, $state->view(), $state->reached);
        if ($term->sort->isNever()) {
            $this->expressions->takeThrows();

            throw $this->expressions->reject($at, 'This expression always throws; write a throw statement.');
        }

        return $term;
    }

    /**
     * Only the paths that did not throw go on to the next statement.
     */
    private function stopThrownPaths(State $state): State
    {
        $thrown = $this->expressions->takeThrows();
        if ($thrown === []) {
            return $state;
        }

        return $state->withReached($this->encoding->define('pc', Smt::all($state->reached, Smt::not(Smt::any(...$thrown)))));
    }

    private function executeReturn(Stmt\Return_ $statement, State $state): State
    {
        if ($statement->expr === null) {
            throw $this->expressions->reject($statement, 'A bare return is not supported: verified methods must return a value.');
        }

        $value = $this->valueOf($statement->expr, $state, $statement);
        $state = $this->stopThrownPaths($state);
        if (!$value->sort->equals($this->resultSort)) {
            if (!$this->expressions->canCoerce($value->sort, $this->resultSort)) {
                throw $this->expressions->reject($statement, sprintf(
                    'Returns %s but the method is declared to return %s.',
                    $value->sort->describe(),
                    $this->resultSort->describe(),
                ));
            }
            $value = $this->expressions->coerce($statement, $value, $this->resultSort);
        }

        if ($this->inlinedReturns !== null) {
            $this->inlinedReturns->add($state->reached, $value);
        } else {
            $this->encoding->recordReturn($state->reached, $value, $statement->getStartLine());
        }

        return $state->withReached(Smt::bool(false));
    }

    /**
     * Why an assignment through `[...]` is refused, by what is written to.
     */
    private function arrayWriteRefusal(Expr\ArrayDimFetch $target, State $state): string
    {
        if ($target->var instanceof Expr\ArrayDimFetch) {
            return 'Nested writes such as $a[$i][$j] = ... are not supported: only appending to a local list variable ($list[] = $value) is.';
        }
        $base = $target->var instanceof Variable && is_string($target->var->name) ? ($state->view()[$target->var->name] ?? null) : null;
        if ($base !== null && $base->sort->isShape()) {
            return $target->dim === null
                ? 'Appending to an array shape is not supported: build a new shape literal.'
                : 'Writing to an array shape is not supported: build a new shape literal, such as [\'net\' => $net, \'vat\' => $vat].';
        }
        if ($base !== null && ($base->sort->isList() || $base->sort->isEmptyList() || ($base->sort->isNullable() && $base->sort->inner?->isList() === true))) {
            return 'Only appending to a list ($list[] = $value) is supported: writing $list[$i] can add a key out of order, which turns the list into another kind of array.';
        }

        return self::LOCAL_WRITES_ONLY;
    }

    /**
     * @param array<Stmt> $thenStatements
     * @param array<Stmt\ElseIf_> $elseIfs
     */
    private function executeIf(
        Stmt\If_ $at,
        Expr $conditionExpression,
        array $thenStatements,
        array $elseIfs,
        ?Stmt\Else_ $else,
        State $state,
    ): State {
        $view = $state->view();
        $condition = $this->encoding->define(
            'cond',
            $this->expressions->translateBoolean($conditionExpression, $view, $state->reached),
        );

        $thenState = $this->execute(
            $thenStatements,
            $this->branch($state, $condition)->withView($this->expressions->narrow($conditionExpression, true, $view)),
        );
        $elseBase = $this->branch($state, Smt::not($condition))
            ->withView($this->expressions->narrow($conditionExpression, false, $view));

        if ($elseIfs !== []) {
            $next = $elseIfs[0];
            $elseState = $this->executeIf($at, $next->cond, $next->stmts, array_slice($elseIfs, 1), $else, $elseBase);
        } elseif ($else !== null) {
            $elseState = $this->execute($else->stmts, $elseBase);
        } else {
            $elseState = $elseBase;
        }

        return $this->merge($at, $condition, $thenState, $elseState);
    }

    private function branch(State $state, Term $condition): State
    {
        return $state->withReached($this->encoding->define('pc', Smt::all($state->reached, $condition)));
    }

    private function merge(Node $at, Term $condition, State $then, State $else): State
    {
        if ($then->reached->isFalse()) {
            return $else;
        }
        if ($else->reached->isFalse()) {
            return $then;
        }

        $variables = [];
        foreach ($then->variables as $name => $thenValue) {
            $elseValue = $else->variables[$name] ?? null;
            if ($elseValue === null) {
                continue;
            }
            $built = $this->isBuiltTerm($thenValue) && $this->isBuiltTerm($elseValue);
            if (!$thenValue->sort->equals($elseValue->sort)) {
                // `[]` takes the type of the list in the other branch.
                if ($thenValue->sort->isEmptyList() && $this->expressions->canCoerce($thenValue->sort, $elseValue->sort)) {
                    $thenValue = $this->expressions->coerce($at, $thenValue, $elseValue->sort);
                } elseif ($elseValue->sort->isEmptyList() && $this->expressions->canCoerce($elseValue->sort, $thenValue->sort)) {
                    $elseValue = $this->expressions->coerce($at, $elseValue, $thenValue->sort);
                } else {
                    throw $this->expressions->reject($at, sprintf('Variable $%s has a different type in each branch.', $name));
                }
            }

            $variables[$name] = $thenValue->smt === $elseValue->smt
                ? $thenValue
                : $this->encoding->define($name, Smt::ite($condition, $thenValue, $elseValue));
            if ($built) {
                $this->built[$variables[$name]->smt] = true;
            }
        }

        $narrowed = [];
        foreach ($then->narrowed as $key => $thenValue) {
            $elseValue = $else->narrowed[$key] ?? null;
            if ($elseValue === null || !$thenValue->sort->equals($elseValue->sort)) {
                continue;
            }

            $narrowed[$key] = $thenValue->smt === $elseValue->smt
                ? $thenValue
                : $this->encoding->define('nn', Smt::ite($condition, $thenValue, $elseValue));
        }

        return new State(
            $variables,
            $this->encoding->define('pc', Smt::any($then->reached, $else->reached)),
            $narrowed,
        );
    }

    private function explainUnsupported(Stmt $statement): string
    {
        return match (true) {
            $statement instanceof Stmt\While_,
            $statement instanceof Stmt\Do_,
            $statement instanceof Stmt\For_,
            $statement instanceof Stmt\Foreach_
                => 'Loops are not supported yet: termination and loop invariants cannot be established. Read a list with count($list), $list[$i] and in_array() instead.',
            $statement instanceof Stmt\Switch_
                => 'switch is not supported; use match.',
            $statement instanceof Stmt\Echo_,
            $statement instanceof Stmt\InlineHTML,
            $statement instanceof Stmt\Global_,
            $statement instanceof Stmt\Static_
                => 'Side effects and global or static state are forbidden in verified methods.',
            $statement instanceof Stmt\Unset_
                => 'unset() is not supported: removing an element leaves a gap in the keys of a list, which is then no longer a list. Build the list without that element, with a literal or with $list[] = $value.',
            $statement instanceof Stmt\TryCatch
                => 'try/catch is not supported: verified methods must not rely on exceptions.',
            default => sprintf('Statement %s is not part of the verified subset.', $statement->getType()),
        };
    }
}
