<?php

declare(strict_types=1);

namespace PhpstanContracts\Smt;

use InvalidArgumentException;
use LogicException;

/**
 * Term constructors. Boolean connectives fold literals so the symbolic executor
 * can tell statically that a path is dead.
 *
 * `eq()` is structural equality, used to define constants. `phpEquals()` is PHP's
 * `===` between two values of the same sort, which differs for floats (NaN, -0.0).
 */
final class Smt
{
    public static function int(int $value): Term
    {
        if ($value >= 0) {
            return new Term((string) $value, Sort::int(), true);
        }

        // -PHP_INT_MIN overflows, so the digits are taken from the string form.
        return new Term('(- ' . ltrim((string) $value, '-') . ')', Sort::int(), true);
    }

    public static function bool(bool $value): Term
    {
        return new Term($value ? 'true' : 'false', Sort::bool(), true);
    }

    public static function symbol(string $name, Sort $sort): Term
    {
        return new Term($name, $sort, true);
    }

    /**
     * The value of a `throw` expression: there is none, the path ends.
     */
    public static function never(): Term
    {
        return new Term('never', Sort::never(), true);
    }

    public static function nullLiteral(): Term
    {
        return new Term('null', Sort::null(), true);
    }

    public static function not(Term $term): Term
    {
        if ($term->isTrue()) {
            return self::bool(false);
        }
        if ($term->isFalse()) {
            return self::bool(true);
        }
        if (str_starts_with($term->smt, '(not ')) {
            return new Term(substr($term->smt, 5, -1), Sort::bool());
        }

        return new Term('(not ' . $term->smt . ')', Sort::bool());
    }

    public static function all(Term ...$terms): Term
    {
        $kept = [];
        foreach ($terms as $term) {
            if ($term->isFalse()) {
                return self::bool(false);
            }
            if (!$term->isTrue()) {
                $kept[] = $term->smt;
            }
        }

        return match (count($kept)) {
            0 => self::bool(true),
            1 => new Term($kept[0], Sort::bool(), self::isAtom($kept[0])),
            default => new Term('(and ' . implode(' ', $kept) . ')', Sort::bool()),
        };
    }

    public static function any(Term ...$terms): Term
    {
        $kept = [];
        foreach ($terms as $term) {
            if ($term->isTrue()) {
                return self::bool(true);
            }
            if (!$term->isFalse()) {
                $kept[] = $term->smt;
            }
        }

        return match (count($kept)) {
            0 => self::bool(false),
            1 => new Term($kept[0], Sort::bool(), self::isAtom($kept[0])),
            default => new Term('(or ' . implode(' ', $kept) . ')', Sort::bool()),
        };
    }

    public static function implies(Term $premise, Term $conclusion): Term
    {
        return self::any(self::not($premise), $conclusion);
    }

    /**
     * Structural equality, as SMT-LIB defines it.
     */
    public static function eq(Term $left, Term $right): Term
    {
        self::assertSameSort($left, $right);

        if (self::isIntLiteral($left) && self::isIntLiteral($right)) {
            return self::bool($left->smt === $right->smt);
        }

        if ($left->sort->isBool()) {
            if ($right->isTrue()) {
                return $left;
            }
            if ($right->isFalse()) {
                return self::not($left);
            }
            if ($left->isTrue()) {
                return $right;
            }
            if ($left->isFalse()) {
                return self::not($right);
            }
        }

        return new Term('(= ' . $left->smt . ' ' . $right->smt . ')', Sort::bool());
    }

    /**
     * PHP `===` between two values of the same sort.
     */
    public static function phpEquals(Term $left, Term $right): Term
    {
        self::assertSameSort($left, $right);

        return match ($left->sort->kind) {
            SortKind::Float => new Term('(fp.eq ' . $left->smt . ' ' . $right->smt . ')', Sort::bool()),
            SortKind::Nullable => self::nullableEquals($left, $right),
            SortKind::Record, SortKind::Shape, SortKind::List, SortKind::EmptyList, SortKind::Null, SortKind::Never => throw new LogicException('Records, arrays, null and never have no PHP equality here.'),
            default => self::eq($left, $right),
        };
    }

    /**
     * @param '<'|'<='|'>'|'>=' $operator
     */
    public static function compare(string $operator, Term $left, Term $right): Term
    {
        return new Term('(' . $operator . ' ' . $left->smt . ' ' . $right->smt . ')', Sort::bool());
    }

    /**
     * @param '+'|'-'|'*' $operator
     */
    public static function arithmetic(string $operator, Term $left, Term $right): Term
    {
        return new Term('(' . $operator . ' ' . $left->smt . ' ' . $right->smt . ')', Sort::int());
    }

    public static function negate(Term $term): Term
    {
        return new Term('(- ' . $term->smt . ')', Sort::int());
    }

    public static function ite(Term $condition, Term $then, Term $else): Term
    {
        self::assertSameSort($then, $else);

        if ($condition->isTrue() || $then->smt === $else->smt) {
            return $then;
        }
        if ($condition->isFalse()) {
            return $else;
        }

        return new Term(
            '(ite ' . $condition->smt . ' ' . $then->smt . ' ' . $else->smt . ')',
            $then->sort,
        );
    }

    public static function call(string $function, Sort $returns, Term ...$arguments): Term
    {
        return new Term(
            '(' . $function . ' ' . implode(' ', array_map(static fn (Term $t): string => $t->smt, $arguments)) . ')',
            $returns,
        );
    }

    public static function inRange(Term $term, int $min, int $max): Term
    {
        return self::all(
            self::compare('>=', $term, self::int($min)),
            self::compare('<=', $term, self::int($max)),
        );
    }

    public static function floatLiteral(float $value): Term
    {
        if (is_nan($value)) {
            return new Term('(_ NaN 11 53)', Sort::float(), true);
        }
        if (is_infinite($value)) {
            return new Term($value > 0 ? '(_ +oo 11 53)' : '(_ -oo 11 53)', Sort::float(), true);
        }

        $bits = unpack('J', pack('E', $value));
        $raw = is_array($bits) ? (int) $bits[1] : 0;
        $sign = ($raw >> 63) & 1;
        $exponent = ($raw >> 52) & 0x7FF;
        $mantissa = $raw & 0xFFFFFFFFFFFFF;

        return new Term(
            sprintf(
                '(fp #b%d #b%s #b%s)',
                $sign,
                str_pad(decbin($exponent), 11, '0', STR_PAD_LEFT),
                str_pad(decbin($mantissa), 52, '0', STR_PAD_LEFT),
            ),
            Sort::float(),
            true,
        );
    }

    /**
     * @param 'add'|'sub'|'mul'|'div' $operation
     */
    public static function fpArithmetic(string $operation, Term $left, Term $right): Term
    {
        return new Term(sprintf('(fp.%s RNE %s %s)', $operation, $left->smt, $right->smt), Sort::float());
    }

    /**
     * @param 'fp.neg'|'fp.abs' $function
     */
    public static function fpUnary(string $function, Term $term): Term
    {
        return new Term(sprintf('(%s %s)', $function, $term->smt), Sort::float());
    }

    public static function fpSqrt(Term $term): Term
    {
        return new Term(sprintf('(fp.sqrt RNE %s)', $term->smt), Sort::float());
    }

    /**
     * @param 'RTN'|'RTP' $mode RTN rounds toward negative infinity (floor), RTP toward positive (ceil)
     */
    public static function fpRound(string $mode, Term $term): Term
    {
        return new Term(sprintf('(fp.roundToIntegral %s %s)', $mode, $term->smt), Sort::float());
    }

    /**
     * @param 'fp.lt'|'fp.leq'|'fp.gt'|'fp.geq'|'fp.eq' $function
     */
    public static function fpCompare(string $function, Term $left, Term $right): Term
    {
        return new Term(sprintf('(%s %s %s)', $function, $left->smt, $right->smt), Sort::bool());
    }

    /**
     * @param 'fp.isNaN'|'fp.isInfinite'|'fp.isZero' $function
     */
    public static function fpIs(string $function, Term $term): Term
    {
        return new Term(sprintf('(%s %s)', $function, $term->smt), Sort::bool());
    }

    /**
     * PHP converts an int operand to the nearest double (ties to even).
     */
    public static function intToFloat(Term $term): Term
    {
        return new Term(sprintf('((_ to_fp 11 53) RNE (to_real %s))', $term->smt), Sort::float());
    }

    /**
     * (int) on a float, truncating toward zero. Only meaningful for finite values
     * inside the int range, which the caller checks.
     */
    public static function floatToInt(Term $term): Term
    {
        return new Term(sprintf('(sbv_to_int ((_ fp.to_sbv 64) RTZ %s))', $term->smt), Sort::int());
    }

    public static function stringLiteral(string $value): Term
    {
        $encoded = '';
        foreach (str_split($value) as $character) {
            $code = ord($character);
            $encoded .= $code >= 0x20 && $code <= 0x7E && $character !== '"' && $character !== '\\'
                ? $character
                : sprintf('\u{%x}', $code);
        }

        return new Term('"' . $encoded . '"', Sort::string(), true);
    }

    /**
     * PHP strings are byte strings: every character of an input is in 0..255.
     */
    public static function isByteString(Term $term): Term
    {
        return new Term(
            sprintf('(str.in_re %s (re.* (re.range "\u{0}" "\u{ff}")))', $term->smt),
            Sort::bool(),
        );
    }

    public static function concat(Term $left, Term $right): Term
    {
        return new Term(sprintf('(str.++ %s %s)', $left->smt, $right->smt), Sort::string());
    }

    public static function length(Term $term): Term
    {
        return new Term(sprintf('(str.len %s)', $term->smt), Sort::int());
    }

    public static function none(Sort $nullable): Term
    {
        return new Term('none_' . $nullable->smt, $nullable, true);
    }

    public static function some(Term $value): Term
    {
        $sort = Sort::nullable($value->sort);

        return new Term(sprintf('(some_%s %s)', $sort->smt, $value->smt), $sort);
    }

    public static function isNone(Term $nullable): Term
    {
        return new Term(sprintf('(= %s none_%s)', $nullable->smt, $nullable->sort->smt), Sort::bool());
    }

    public static function unwrap(Term $nullable): Term
    {
        $inner = $nullable->sort->inner ?? throw new LogicException('unwrap() needs a nullable term.');

        return new Term(sprintf('(val_%s %s)', $nullable->sort->smt, $nullable->smt), $inner);
    }

    /**
     * @param list<Term> $fields in declaration order
     */
    public static function construct(Sort $record, array $fields): Term
    {
        $definition = $record->record ?? throw new LogicException('construct() needs a record sort.');

        return new Term(
            sprintf('(%s %s)', $definition->constructorName(), implode(' ', array_map(static fn (Term $t): string => $t->smt, $fields))),
            $record,
        );
    }

    public static function field(Term $record, string $field): Term
    {
        $definition = $record->sort->record ?? throw new LogicException('field() needs a record term.');
        $sort = $definition->fields[$field] ?? throw new LogicException('Unknown field ' . $field);

        return new Term(sprintf('(%s %s)', $definition->accessor($field), $record->smt), $sort);
    }

    /**
     * An array shape built from the terms of its fields, given in the order of the sort.
     *
     * @param list<Term> $fields
     */
    public static function shape(Sort $shape, array $fields): Term
    {
        $definition = $shape->shape ?? throw new LogicException('shape() needs a shape sort.');

        return new Term(
            sprintf('(%s %s)', $definition->constructorName(), implode(' ', array_map(static fn (Term $t): string => $t->smt, $fields))),
            $shape,
            components: $fields,
        );
    }

    /**
     * The value under a key of an array shape.
     */
    public static function shapeField(Term $shape, string $key): Term
    {
        $definition = $shape->sort->shape ?? throw new LogicException('shapeField() needs a shape term.');
        $position = array_search($key, $definition->keys(), true);
        if ($position === false) {
            throw new LogicException('Unknown key ' . $key);
        }
        if ($shape->components !== null) {
            return $shape->components[$position];
        }

        return new Term(sprintf('(%s %s)', $definition->accessorAt($position), $shape->smt), $definition->fields[$key]);
    }

    /**
     * `count($list)`.
     */
    public static function listLength(Term $list): Term
    {
        return new Term(sprintf('(%s_len %s)', $list->sort->smt, $list->smt), Sort::int());
    }

    /**
     * The cell at a key. It is the element only where `0 <= $index < count($list)`; the caller
     * either proves that or guards the read, because PHP warns and yields null elsewhere.
     */
    public static function listItem(Term $list, Term $index): Term
    {
        $element = $list->sort->element ?? throw new LogicException('listItem() needs a list term.');

        return new Term(sprintf('(select (%s_items %s) %s)', $list->sort->smt, $list->smt, $index->smt), $element);
    }

    /**
     * The mathematical sum of the first `$count` elements of a `list<int>`, which is
     * `array_sum()` as long as no prefix leaves the PHP int range.
     */
    public static function prefixSum(Term $list, Term $count): Term
    {
        return new Term(sprintf('(sum_List_Int (%s_items %s) %s)', $list->sort->smt, $list->smt, $count->smt), Sort::int());
    }

    /**
     * Every prefix sum of a `list<int>` is an int. PHP's `array_sum()` returns a float from the
     * first prefix that is not, so this is what makes {@see self::prefixSum()} its result.
     * `$list` must be an atom, since it appears under the quantifier.
     */
    public static function prefixSumsInRange(Term $list): Term
    {
        $prefix = self::symbol('j!0', Sort::int());
        $sum = self::prefixSum($list, $prefix);

        return self::forall('j!0', self::implies(
            self::all(self::compare('<=', self::int(1), $prefix), self::compare('<=', $prefix, self::listLength($list))),
            self::inRange($sum, PHP_INT_MIN, PHP_INT_MAX),
        ), [$sum]);
    }

    /**
     * `[a, b]`: the cells 0 and 1 of a fresh, unconstrained list `$base` are replaced. The cells
     * beyond the length are junk that nothing reads.
     *
     * @param list<Term> $elements
     */
    public static function listLiteral(Sort $list, array $elements, Term $base): Term
    {
        $items = sprintf('(%s_items %s)', $list->smt, $base->smt);
        foreach ($elements as $position => $element) {
            $items = sprintf('(store %s %d %s)', $items, $position, $element->smt);
        }

        return new Term(sprintf('(mk_%s %d %s)', $list->smt, count($elements), $items), $list);
    }

    /**
     * `[]` of a given list sort, on top of a fresh, unconstrained list `$base`.
     */
    public static function emptyListOf(Sort $list, Term $base): Term
    {
        return self::listLiteral($list, [], $base);
    }

    /**
     * `$list[] = $element`: the list with one more cell at the key `count($list)`.
     */
    public static function listAppend(Term $list, Term $element): Term
    {
        $length = self::listLength($list)->smt;

        return new Term(
            sprintf('(mk_%1$s (+ %2$s 1) (store (%1$s_items %3$s) %2$s %4$s))', $list->sort->smt, $length, $list->smt, $element->smt),
            $list->sort,
        );
    }

    /**
     * `0 <= $index < count($list)`: the key exists.
     */
    public static function hasKey(Term $list, Term $index): Term
    {
        return self::all(
            self::compare('<=', self::int(0), $index),
            self::compare('<', $index, self::listLength($list)),
        );
    }

    /**
     * `in_array()` over a list: some key below `count($list)` whose element satisfies `$matches`.
     * `$list` must be an atom, since it appears under the quantifier.
     *
     * @param callable(Term): Term $matches the equality of one element with the needle
     */
    public static function listContains(Term $list, callable $matches): Term
    {
        $key = self::symbol('k!0', Sort::int());
        $element = self::listItem($list, $key);

        return self::exists('k!0', self::all(self::hasKey($list, $key), $matches($element)), [$element]);
    }

    /**
     * `$a === $b` on two lists of the same sort whose elements compare by structure: the same
     * count, and the same element at every key below it. Both lists must be atoms.
     */
    public static function listIdentical(Term $left, Term $right): Term
    {
        self::assertSameSort($left, $right);
        $key = self::symbol('k!0', Sort::int());
        $leftItem = self::listItem($left, $key);
        $rightItem = self::listItem($right, $key);

        return self::all(
            self::eq(self::listLength($left), self::listLength($right)),
            self::forall(
                'k!0',
                self::implies(self::hasKey($left, $key), self::eq($leftItem, $rightItem)),
                [$leftItem, $rightItem],
            ),
        );
    }

    /**
     * `(forall ((k!0 Int)) (! body :pattern (...)))`.
     *
     * @param list<Term> $patterns
     */
    public static function forall(string $variable, Term $body, array $patterns): Term
    {
        return self::quantifier('forall', $variable, $body, $patterns);
    }

    /**
     * @param list<Term> $patterns
     */
    public static function exists(string $variable, Term $body, array $patterns = []): Term
    {
        return self::quantifier('exists', $variable, $body, $patterns);
    }

    /**
     * @param 'forall'|'exists' $quantifier
     * @param list<Term> $patterns
     */
    private static function quantifier(string $quantifier, string $variable, Term $body, array $patterns): Term
    {
        if ($body->isTrue() || $body->isFalse()) {
            return $body;
        }

        $text = $body->smt;
        if ($patterns !== []) {
            $text = sprintf('(! %s %s)', $text, implode(' ', array_map(static fn (Term $pattern): string => ':pattern (' . $pattern->smt . ')', $patterns)));
        }

        return new Term(sprintf('(%s ((%s Int)) %s)', $quantifier, $variable, $text), Sort::bool());
    }

    private static function nullableEquals(Term $left, Term $right): Term
    {
        return self::any(
            self::all(self::isNone($left), self::isNone($right)),
            self::all(
                self::not(self::isNone($left)),
                self::not(self::isNone($right)),
                self::phpEquals(self::unwrap($left), self::unwrap($right)),
            ),
        );
    }

    private static function isIntLiteral(Term $term): bool
    {
        return $term->sort->isInt() && preg_match('/^(\d+|\(- \d+\))$/', $term->smt) === 1;
    }

    private static function isAtom(string $smt): bool
    {
        return !str_contains($smt, ' ');
    }

    private static function assertSameSort(Term $left, Term $right): void
    {
        if (!$left->sort->equals($right->sort)) {
            throw new InvalidArgumentException(sprintf(
                'Sort mismatch: %s vs %s',
                $left->sort->smt,
                $right->sort->smt,
            ));
        }
    }
}
