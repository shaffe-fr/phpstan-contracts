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
    public function __construct(
        private readonly ExpressionTranslator $expressions,
        private readonly Encoding $encoding,
        private readonly Sort $resultSort,
        private readonly ?InlinedReturns $inlinedReturns = null,
    ) {
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
        if (!$target instanceof Variable || !is_string($target->name) || $target->name === 'this') {
            throw $this->expressions->reject($at, 'Only assignments to local variables are allowed (no property, array or dynamic writes).');
        }

        $name = $target->name;
        $term = $this->valueOf($value, $state, $at);
        $previous = $state->variables[$name] ?? null;

        if ($previous === null && $term->sort->isNull()) {
            throw $this->expressions->reject($at, sprintf('Cannot infer a type for $%s from null; assign a value of the type it will hold, or give a nullable parameter.', $name));
        }

        $stored = $term;
        if ($previous !== null && !$previous->sort->equals($term->sort)) {
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

        return $this->stopThrownPaths($state->withVariable($name, $this->encoding->define($name, $stored), $narrowedValue));
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
            if (!$this->canCoerce($value->sort)) {
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

    private function canCoerce(Sort $valueSort): bool
    {
        if (!$this->resultSort->isNullable()) {
            return false;
        }

        return $valueSort->isNull() || ($this->resultSort->inner !== null && $this->resultSort->inner->equals($valueSort));
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
            if (!$thenValue->sort->equals($elseValue->sort)) {
                throw $this->expressions->reject($at, sprintf('Variable $%s has a different type in each branch.', $name));
            }

            $variables[$name] = $thenValue->smt === $elseValue->smt
                ? $thenValue
                : $this->encoding->define($name, Smt::ite($condition, $thenValue, $elseValue));
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
                => 'Loops are not supported: termination and loop invariants cannot be established.',
            $statement instanceof Stmt\Switch_
                => 'switch is not supported; use match.',
            $statement instanceof Stmt\Echo_,
            $statement instanceof Stmt\InlineHTML,
            $statement instanceof Stmt\Global_,
            $statement instanceof Stmt\Static_,
            $statement instanceof Stmt\Unset_
                => 'Side effects and global or static state are forbidden in verified methods.',
            $statement instanceof Stmt\TryCatch
                => 'try/catch is not supported: verified methods must not rely on exceptions.',
            default => sprintf('Statement %s is not part of the verified subset.', $statement->getType()),
        };
    }
}
