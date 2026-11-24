<?php

declare(strict_types=1);

namespace PhpstanContracts\Translate;

use Closure;
use InvalidArgumentException;
use PhpstanContracts\Contract\ContractClause;
use PhpstanContracts\Exception\VerificationRejected;
use PhpstanContracts\Smt\Smt;
use PhpParser\Node\Arg;
use PhpParser\Node\Expr;
use PhpParser\Node\Expr\BinaryOp;
use PhpParser\Node\Name;
use PhpParser\Node\Scalar\Int_;
use PhpParser\PrettyPrinter\Standard;

/**
 * How many iterations of a `foreach` the proof unrolls, and how many it may unroll in all.
 *
 * The number only decides how much of the loop is translated. It is never assumed: the
 * translation adds a `loopBound` obligation wherever the loop could need more iterations, so a
 * wrong or missing bound makes the proof fail, and never makes it unsound.
 *
 * A bound is read from the top-level conjuncts of the `#[Requires]` of the method that holds the
 * loop: `count($lines) <= 50`, `count($lines) < 51`, `50 >= count($lines)`, `50 > count($lines)`
 * and `count($lines) === 50`, with an int literal or a class constant that folds to an int.
 */
final class LoopBounds
{
    public const MAXIMUM_UNROLL = 10000;

    /** @var list<array{string, int, string}> the printed list expression, its bound, the `#[Requires]` source */
    private array $bounds = [];

    private readonly Standard $printer;

    /**
     * @param list<ContractClause> $requires
     * @param Closure(Expr): ?int $fold the value of an expression that is an int constant, or null
     */
    public function __construct(
        array $requires,
        private readonly Closure $fold,
        private readonly int $default,
        private readonly int $maximum,
    ) {
        $this->printer = new Standard();
        foreach ($requires as $clause) {
            foreach (self::conjuncts($clause->expression) as $conjunct) {
                $bound = $this->boundOfConjunct($conjunct);
                if ($bound !== null) {
                    $this->bounds[] = [$bound[0], max(0, $bound[1]), $clause->source];
                }
            }
        }
    }

    /**
     * The bounds of a method, with class constants read by the translator of its body.
     *
     * @param list<ContractClause> $requires
     */
    public static function of(array $requires, ExpressionTranslator $translator, int $default, int $maximum): self
    {
        $fold = static function (Expr $expression) use ($translator): ?int {
            if (!$expression instanceof Expr\ClassConstFetch) {
                return null;
            }
            try {
                $term = $translator->translate($expression, [], Smt::bool(true));
            } catch (VerificationRejected) {
                return null;
            }

            return $term->sort->isInt() && preg_match('/^\d+$/', $term->smt) === 1 ? (int) $term->smt : null;
        };

        return new self($requires, $fold, $default, $maximum);
    }

    /**
     * The iterations to unroll for a loop over `$list`, and the `#[Requires]` that gave the number
     * (null when it comes from the list itself or from `contracts.loopUnroll`).
     *
     * @return array{int, string|null}
     */
    public function boundOf(Expr $list): array
    {
        $text = $this->printer->prettyPrintExpr($list);

        $own = null;
        foreach ($this->bounds as $bound) {
            if ($bound[0] === $text && ($own === null || $bound[1] < $own[1])) {
                $own = $bound;
            }
        }
        if ($own !== null) {
            return [$own[1], $own[2]];
        }

        $largest = null;
        foreach ($this->bounds as $bound) {
            if ($largest === null || $bound[1] > $largest[1]) {
                $largest = $bound;
            }
        }
        if ($largest !== null) {
            return [$largest[1], $largest[2]];
        }

        $size = self::literalSize($list);
        if ($size !== null) {
            return [$size, null];
        }

        return [$this->default, null];
    }

    /**
     * The number of unrolled iterations of a loop nest: the product of the bounds from the
     * outermost loop to the innermost, saturated at the largest int.
     *
     * @param list<int> $bounds
     */
    public static function product(array $bounds): int
    {
        $product = 1;
        foreach ($bounds as $bound) {
            if ($bound !== 0 && $product > intdiv(PHP_INT_MAX, $bound)) {
                return PHP_INT_MAX;
            }
            $product *= $bound;
        }

        return $product;
    }

    /**
     * @throws InvalidArgumentException when the settings are out of range
     */
    public static function validateLimits(int $default, int $maximum): void
    {
        if ($maximum < 1 || $maximum > self::MAXIMUM_UNROLL) {
            throw new InvalidArgumentException(sprintf('contracts.maxLoopUnroll must be between 1 and %d, %d given.', self::MAXIMUM_UNROLL, $maximum));
        }
        if ($default < 1 || $default > $maximum) {
            throw new InvalidArgumentException(sprintf('contracts.loopUnroll must be between 1 and contracts.maxLoopUnroll (%d), %d given.', $maximum, $default));
        }
    }

    /**
     * The message for a loop nest that would be unrolled more than the maximum, or null when
     * it fits. `$enclosing` are the bounds of the loops around this one, outermost first.
     *
     * @param list<int> $enclosing
     */
    public function budgetRefusal(array $enclosing, int $bound): ?string
    {
        $product = self::product([...$enclosing, $bound]);
        if ($product <= $this->maximum) {
            return null;
        }

        if ($enclosing === []) {
            return sprintf(
                'This foreach would be unrolled %d times, more than contracts.maxLoopUnroll (%d). Bound the list more tightly with #[Requires], or raise contracts.maxLoopUnroll.',
                $product,
                $this->maximum,
            );
        }

        return sprintf(
            'These nested loops would be unrolled %s = %d times, more than contracts.maxLoopUnroll (%d). Bound the lists more tightly with #[Requires], extract the inner loop into a smaller method of this file, or raise contracts.maxLoopUnroll.',
            implode(' × ', [...$enclosing, $bound]),
            $product,
            $this->maximum,
        );
    }

    /**
     * @return list<Expr>
     */
    private static function conjuncts(Expr $expression): array
    {
        if ($expression instanceof BinaryOp\BooleanAnd || $expression instanceof BinaryOp\LogicalAnd) {
            return [...self::conjuncts($expression->left), ...self::conjuncts($expression->right)];
        }

        return [$expression];
    }

    /**
     * @return array{string, int}|null the printed list expression and the largest count it allows
     */
    private function boundOfConjunct(Expr $conjunct): ?array
    {
        if (!$conjunct instanceof BinaryOp) {
            return null;
        }

        // [count side, constant side, adjustment of the constant]
        $forms = match (true) {
            $conjunct instanceof BinaryOp\SmallerOrEqual => [[$conjunct->left, $conjunct->right, 0]],
            $conjunct instanceof BinaryOp\Smaller => [[$conjunct->left, $conjunct->right, -1]],
            $conjunct instanceof BinaryOp\GreaterOrEqual => [[$conjunct->right, $conjunct->left, 0]],
            $conjunct instanceof BinaryOp\Greater => [[$conjunct->right, $conjunct->left, -1]],
            $conjunct instanceof BinaryOp\Identical => [[$conjunct->left, $conjunct->right, 0], [$conjunct->right, $conjunct->left, 0]],
            default => [],
        };

        foreach ($forms as [$count, $constant, $adjustment]) {
            $list = self::countedList($count);
            $value = $list === null ? null : $this->constant($constant);
            if ($list !== null && $value !== null) {
                return [$this->printer->prettyPrintExpr($list), $value === PHP_INT_MIN ? $value : $value + $adjustment];
            }
        }

        return null;
    }

    private static function countedList(Expr $expression): ?Expr
    {
        if (
            !$expression instanceof Expr\FuncCall
            || !$expression->name instanceof Name
            || $expression->name->toLowerString() !== 'count'
            || count($expression->args) !== 1
        ) {
            return null;
        }
        $argument = $expression->args[0];

        return $argument instanceof Arg && !$argument->unpack && $argument->name === null ? $argument->value : null;
    }

    private function constant(Expr $expression): ?int
    {
        return $expression instanceof Int_ ? $expression->value : ($this->fold)($expression);
    }

    private static function literalSize(Expr $list): ?int
    {
        if (!$list instanceof Expr\Array_) {
            return null;
        }
        foreach ($list->items as $item) {
            if ($item->unpack || $item->key !== null) {
                return null;
            }
        }

        return count($list->items);
    }
}
