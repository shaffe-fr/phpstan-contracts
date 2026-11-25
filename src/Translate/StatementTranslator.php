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
use PhpParser\PrettyPrinter\Standard;

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
    private const FOREACH_TARGETS = 'The key and the value of a foreach must be local variables.';

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

    /**
     * One frame per iteration being translated, outermost loop first: the states that reached
     * a `break` or a `continue` aimed at it. A translator only knows the loops of its own body,
     * so a callee cannot jump into the loop of its caller, as in PHP.
     *
     * @var list<array{breaks: list<State>, continues: list<State>}>
     */
    private array $frames = [];

    private readonly Standard $printer;

    /**
     * @param array<string, Term> $entry the parameters on entry, which a `#[Requires]` can name
     * @param list<string> $calls in an inlined method, the calls that lead to it from the verified method
     */
    public function __construct(
        private readonly ExpressionTranslator $expressions,
        private readonly Encoding $encoding,
        private readonly Sort $resultSort,
        private readonly LoopBounds $loopBounds,
        private readonly array $entry = [],
        private readonly array $calls = [],
        private readonly ?InlinedReturns $inlinedReturns = null,
    ) {
        $this->printer = new Standard();
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
            $statement instanceof Stmt\Foreach_ => $this->executeForeach($statement, $state),
            $statement instanceof Stmt\Break_,
            $statement instanceof Stmt\Continue_ => $this->executeJump($statement, $state),
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
            // As a statement the value of the expression is discarded, so prefix and postfix are the same as `+= 1` / `-= 1`.
            $current = $expr->var instanceof Variable && is_string($expr->var->name) ? ($state->view()[$expr->var->name] ?? null) : null;
            if ($current !== null && !$current->sort->isNumeric()) {
                // On null, a string or a bool, ++ and -- do not behave like + 1 and - 1.
                throw $this->expressions->reject($expr, sprintf('Increment and decrement are only supported on an int or a float variable, not on a %s.', $current->sort->describe()));
            }
            $one = new Node\Scalar\Int_(1, $expr->getAttributes());
            $isIncrement = $expr instanceof Expr\PreInc || $expr instanceof Expr\PostInc;

            return $this->assign(
                $expr->var,
                $isIncrement ? new BinaryOp\Plus($expr->var, $one, $expr->getAttributes()) : new BinaryOp\Minus($expr->var, $one, $expr->getAttributes()),
                $state,
                $expr,
            );
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
    private function bind(string $name, Term $term, State $state, Node $at, bool $built = false): State
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

    /**
     * `foreach ($list as $item)` and `foreach ($list as $i => $item)` over a list, by value.
     *
     * The loop is unrolled: iteration `k` runs the body on the element `k` under the condition
     * `k < count($list)`, for `k` below a bound `N`. What the unrolling leaves out is every
     * iteration after the `N`th, so wherever a path can still be in the loop after `N`
     * iterations, a `loopBound` obligation says that the list has at most `N` elements. The bound
     * is never assumed: it only decides how much of the loop is translated.
     *
     * PHP iterates a by-value `foreach` over the array as it was on entry, which is why the list
     * is translated once, into a term of its own, and every iteration reads that term: writing
     * the variable inside the loop does not change what is iterated.
     */
    private function executeForeach(Stmt\Foreach_ $loop, State $state): State
    {
        [$keyName, $valueName] = $this->foreachTargets($loop);
        $this->encoding->needsSolverFallback = true;

        $list = $this->valueOf($loop->expr, $state, $loop);
        $state = $this->stopThrownPaths($state);
        $this->requireIterableList($loop, $list);
        $list = $this->encoding->define('iter', $list);

        [$bound, $origin] = $this->loopBounds->boundOf($loop->expr);
        $refusal = $this->loopBounds->budgetRefusal($this->encoding->enclosingBounds(), $bound);
        if ($refusal !== null) {
            throw $this->expressions->reject($loop, $refusal);
        }

        $line = $loop->getStartLine();
        $length = Smt::listLength($list);
        if ($this->encoding->enclosingBounds() === []) {
            $this->encoding->noteSplitLength($length->smt, $bound, $this->printer->prettyPrintExpr($loop->expr));
        }
        $this->encoding->noteUnrolledLoop($line, new LoopBound($bound, $this->printer->prettyPrintExpr($loop->expr), $origin, $this->canBeNamedInContract($loop->expr, $state)));
        $exits = [];
        $current = $state;
        for ($k = 0; $k < $bound && !$current->reached->isFalse(); ++$k) {
            $more = $this->encoding->define('more', Smt::compare('<', Smt::int($k), $length));
            $exits[] = $this->branch($current, Smt::not($more));
            $inside = $this->branch($current, $more);
            if ($keyName !== null) {
                $inside = $this->bind($keyName, Smt::int($k), $inside, $loop);
            }
            $inside = $this->bind($valueName, Smt::listItem($list, Smt::int($k)), $inside, $loop);

            $this->encoding->enterIteration($line, $k, $bound);
            $this->frames[] = ['breaks' => [], 'continues' => []];
            try {
                $end = $this->execute($loop->stmts, $inside);
                $frame = $this->frames[array_key_last($this->frames)];
            } finally {
                array_pop($this->frames);
                $this->encoding->leaveIteration();
            }

            // Every path that leaves the iteration is disjoint from the others: the paths that
            // `break` go to the exit, the ones that end it or `continue` go to the next iteration.
            array_push($exits, ...$frame['breaks']);
            $current = $this->mergeAll($loop, [$end, ...$frame['continues']], $state);
        }

        // A path that finished the last unrolled iteration is exact only if the list has no
        // more elements than that. Once checked, the obligation is assumed, like every other.
        if (!$current->reached->isFalse()) {
            $subject = $this->printer->prettyPrintExpr($loop->expr);
            $this->encoding->addObligation(new Obligation(
                ObligationKind::LoopBound,
                $line,
                sprintf('count(%s) <= %d', $subject, $bound),
                $current->reached,
                Smt::compare('<=', $length, Smt::int($bound)),
                $this->calls,
                loop: new LoopBound($bound, $subject, $origin, $this->canBeNamedInContract($loop->expr, $state)),
            ));
            $exits[] = $current;
        }

        return $this->mergeAll($loop, $exits, $state);
    }

    /**
     * `break` and `continue`, with an optional literal level: the state is recorded in the frame
     * of the loop it is aimed at, which merges it where PHP resumes, and the path ends here.
     */
    private function executeJump(Stmt\Break_|Stmt\Continue_ $jump, State $state): State
    {
        $word = $jump instanceof Stmt\Break_ ? 'break' : 'continue';
        if ($this->frames === []) {
            throw $this->expressions->reject($jump, sprintf('%s is only supported inside a foreach.', $word));
        }

        $level = 1;
        if ($jump->num !== null) {
            if (!$jump->num instanceof Node\Scalar\Int_) {
                throw $this->expressions->reject($jump, sprintf('%s needs a literal level, such as %s 2.', $word, $word));
            }
            $level = $jump->num->value;
            if ($level < 1) {
                throw $this->expressions->reject($jump, sprintf('%1$s %2$d is not valid: the level of %1$s starts at 1.', $word, $level));
            }
        }
        $depth = count($this->frames);
        if ($level > $depth) {
            throw $this->expressions->reject($jump, sprintf('%s %d leaves more loops than enclose it here (%d).', $word, $level, $depth));
        }

        $target = $depth - $level;
        $this->frames[$target][$jump instanceof Stmt\Break_ ? 'breaks' : 'continues'][] = $state;

        return $state->withReached(Smt::bool(false));
    }

    /**
     * The key and value variables of a `foreach`, or why the loop is not supported.
     *
     * @return array{string|null, string}
     */
    private function foreachTargets(Stmt\Foreach_ $loop): array
    {
        if ($loop->valueVar instanceof Expr\Array_ || $loop->valueVar instanceof Expr\List_) {
            throw $this->expressions->reject($loop, 'Destructuring in foreach is not supported: iterate with foreach ($list as $item) and read the elements, such as $item[\'net\'].');
        }
        if ($loop->byRef) {
            throw $this->expressions->reject($loop, 'foreach by reference (&$item) changes the list in place and leaves $item a reference to its last element, which is not modelled. Iterate by value and build a new list with $out[] = $value.');
        }

        $key = null;
        if ($loop->keyVar !== null) {
            $key = self::localName($loop->keyVar) ?? throw $this->expressions->reject($loop, self::FOREACH_TARGETS);
        }
        $value = self::localName($loop->valueVar) ?? throw $this->expressions->reject($loop, self::FOREACH_TARGETS);
        if ($key === $value) {
            throw $this->expressions->reject($loop, sprintf('The key and the value of this foreach are both $%s; use two different variables.', $value));
        }

        return [$key, $value];
    }

    private static function localName(Expr $target): ?string
    {
        return $target instanceof Variable && is_string($target->name) && $target->name !== 'this' ? $target->name : null;
    }

    private function requireIterableList(Stmt\Foreach_ $loop, Term $list): void
    {
        $sort = $list->sort;
        if ($sort->isList()) {
            return;
        }

        $message = match (true) {
            $sort->isEmptyList() => 'This list is always empty, so the loop never runs; remove the loop.',
            $sort->isShape() => 'foreach over an array shape is not supported: its keys are not a list and its values can have different types. Read each key, such as $totals[\'net\'].',
            $sort->isNullable() && $sort->inner?->isList() === true => sprintf(
                'foreach over %s, which may be null here: PHP warns "foreach() argument must be of type array|object" and skips the loop. Check it first with !== null.',
                $this->printer->prettyPrintExpr($loop->expr),
            ),
            default => sprintf('foreach is only supported over a list<T>, not over %s %s.', preg_match('/^[aeiou]/', $sort->describe()) === 1 ? 'an' : 'a', $sort->describe()),
        };

        throw $this->expressions->reject($loop, $message);
    }

    /**
     * Whether a `#[Requires]` can state a bound on this list: a parameter, or a property of one,
     * still holding the value it had on entry.
     */
    private function canBeNamedInContract(Expr $list, State $state): bool
    {
        while ($list instanceof Expr\PropertyFetch && $list->name instanceof Node\Identifier) {
            $list = $list->var;
        }
        if (!$list instanceof Variable || !is_string($list->name)) {
            return false;
        }

        $entry = $this->entry[$list->name] ?? null;
        $current = $state->variables[$list->name] ?? null;

        return $entry !== null && $current !== null && $entry->smt === $current->smt;
    }

    /**
     * The states of the paths that leave a loop, merged: a variable keeps its value on each path
     * and is dropped when one of the paths does not define it. The paths are disjoint, so the
     * condition of each one is its own `reached`.
     *
     * @param list<State> $states
     */
    private function mergeAll(Node $at, array $states, State $dead): State
    {
        $states = array_values(array_filter($states, static fn (State $state): bool => !$state->reached->isFalse()));
        if ($states === []) {
            return $dead->withReached(Smt::bool(false));
        }

        $merged = array_pop($states);
        while ($states !== []) {
            $next = array_pop($states);
            $merged = $this->merge($at, $next->reached, $next, $merged);
        }

        return $merged;
    }

    private function explainUnsupported(Stmt $statement): string
    {
        return match (true) {
            $statement instanceof Stmt\While_,
            $statement instanceof Stmt\Do_,
            $statement instanceof Stmt\For_
                => 'Only foreach over a list is supported: a while, do or for loop needs a termination argument, which is not supported yet. Iterate the list with foreach ($list as $i => $item), or read it with count($list) and $list[$i].',
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
