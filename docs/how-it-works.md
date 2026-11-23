# How it works

A checked method goes through four steps:

```
PHP method + attributes
   │  parse contracts, read parameter types from PHPStan
   ▼
symbolic execution of the body, in SSA form
   │  produces definitions, safety obligations and the postconditions to refute
   ▼
one SMT-LIB script, sent to Z3 on standard input
   │  one check per obligation, one per #[Ensures]
   ▼
unsat -> nothing is reported      sat -> a counterexample is turned into a PHPStan error
```

The rule is a normal `PHPStan\Rules\Rule` on `InClassMethodNode`. Parameter types, including PHPDoc ranges such as `int<1, 365>`, come from PHPStan's `Scope`, so the proof uses the same types that the rest of your analysis does.

## A complete example

```php
/**
 * @param int<0, 100000> $price
 * @param int<0, 100> $percent
 */
#[Ensures('$result >= 0 && $result <= $price')]
public static function discounted(int $price, int $percent): int
{
    if ($percent > 50) {
        $percent = 50;
    }

    return $price - intdiv($price * $percent, 100);
}
```

The extension generates this script (a prelude that defines `php_intdiv` and `php_mod` is omitted). First the inputs, with the domains that their types give them, and the declaration of the result:

```smt
(declare-const price@1 Int)
(assert (and (>= price@1 0) (<= price@1 100000)))
(declare-const percent@2 Int)
(assert (and (>= percent@2 0) (<= percent@2 100)))
(declare-const result@3 Int)
```

Then the first check, `@@0`, which asks whether any input satisfies the assumptions. It comes before the body on purpose: the body only defines values from the inputs, so it cannot change the answer, and leaving it out keeps this check cheap even when the body is expensive to reason about.

```smt
(echo "@@0")
(check-sat)
```

Then the body, in SSA form:

```smt
(declare-const cond@4 Bool)
(assert (= cond@4 (> percent@2 50)))
(declare-const pc@5 Bool)
(assert (= pc@5 (not cond@4)))
(declare-const percent@6 Int)
(assert (= percent@6 (ite cond@4 50 percent@2)))
(declare-const pc@7 Bool)
(assert (= pc@7 (or cond@4 pc@5)))
(declare-const t@8 Int)
(assert (= t@8 (* price@1 percent@6)))
(declare-const t@9 Int)
(assert (= t@9 (php_intdiv t@8 100)))
(declare-const t@10 Int)
(assert (= t@10 (- price@1 t@9)))
(assert (or (not pc@7) (= result@3 t@10)))
(declare-const return@11 Bool)
(assert (= return@11 pc@7))
```

Then one check per safety condition. The first one is the overflow of `$price * $percent`:

```smt
(echo "@@1")
(push 1)
(assert (and pc@7 (not (and (>= t@8 (- 9223372036854775808)) (<= t@8 9223372036854775807)))))
(check-sat)
(get-value (price@1 percent@2 result@3 return@11))
(pop 1)
(assert (or (not pc@7) (and (>= t@8 (- 9223372036854775808)) (<= t@8 9223372036854775807))))
```

Checks `@@2` and `@@3` do the same for `t@9` and `t@10`, and the last one is the contract:

```smt
(echo "@@4")
(push 1)
(assert (not (and (>= result@3 0) (<= result@3 price@1))))
(check-sat)
(get-value (price@1 percent@2 result@3 return@11))
(pop 1)
```

Z3 answers `unsat` to `@@1` to `@@4`, so the method is proven and PHPStan reports nothing. The divisor `100` is a constant, so there is no division-by-zero check to run.

## Symbolic execution and SSA

The body is not translated statement by statement into logic. It is executed symbolically, and every assignment creates a new constant:

- `$percent = 50` creates `percent@6`, leaving `percent@2` (the value on entry) untouched. In SSA form each name is assigned once, so the solver never has to reason about "the value of `$percent` at line 14".
- At the end of an `if`, a variable that differs between the branches is merged with `ite`: `percent@6 = (ite cond@4 50 percent@2)`. This plays the role of the phi node of compiler theory.
- `pc@N` is the **path condition**, the condition under which control reaches this point. A `return` records `pc => result = value` and then makes the rest of the code unreachable, which is how early returns work: `pc@5` is the path that did not take the `if`, and `pc@7` is `cond@4 or pc@5`.
- `return@11` is a marker that is true when this `return` is the one taken. It is what lets the counterexample say `(return at line 67)`.

Terms are named after the PHP variable plus a counter (`percent@6`), and every sub-expression with an obligation gets a constant of its own (`t@8`). The script therefore grows linearly with the method, whatever its nesting.

## Safety obligations

Mathematical integers do not overflow, PHP's do, so a proof about `price * percent` is only meaningful if the product is shown to fit. Every integer `+`, `-`, `*`, unary `-`, `abs()` and `intdiv()` gets an overflow obligation, every `intdiv()`, `%` and float `/` a non-zero divisor obligation, every `Enum::from()` a valid-backing-value obligation and every `(int)` of a float a representable-value obligation, each stated under the path condition of the line that contains it. A `match` without a `default` gets an exhaustiveness obligation, and a method that can fall off its end a missing-return obligation.

Obligations are checked in order and **a checked obligation is assumed afterwards** (the `(assert (or (not pc@7) ...))` lines). One mistake is therefore reported once and not again in every later check. This is sound because any failing check already makes the method fail.

A divisor that is a non-zero integer literal is recognised before the script is written, which is why `intdiv(..., 100)` produces no division-by-zero check.

## Contract expressions

Contracts go through the same translator as the body, so a contract can never mean something different from the code it describes. Two differences:

- Arithmetic in a contract is unbounded, so no overflow check is generated for it.
- `$result` refers to the returned value, and parameters refer to their values on entry.

The first check, `@@0`, expects `sat`. It asks whether any input satisfies the parameter types and every `#[Requires]`, and it is the only check that does not look at the body. If the answer is `unsat`, the preconditions contradict each other and every other check would pass for the wrong reason, so the extension reports `contracts.contradictoryRequires`. If the answer is `unknown`, the extension cannot tell, and it reports `contracts.unknown` on the method line next to the results of the other checks, since every proof among them may be vacuous.

Z3 skips a command that it cannot run, prints an error, and goes on. A skipped assertion only makes the problem weaker, but nothing would say so, so any error in the output fails the method with `contracts.solver`. The one expected error is the missing model of the `get-value` that follows an `unsat` or `unknown` answer.

## Counterexamples

For a `sat` answer, `(get-value ...)` returns a value for every parameter, for the result and for the `return@N` markers. A parameter that the formula does not constrain still gets a value. The extension prints the parameters, the result, and the line of the marker that is true:

```
Counterexample: $totalItems = 100000000, $perPage = 1 => $result = 100000001 (return at line 36).
```

The values are an input that exists, found by the solver, which satisfies every assumption, takes the path through the `return` at that line, and breaks the contract.

## Nullable values and null checks

A nullable value is an SMT datatype with two constructors, `none` and `some`. `$x === null` is `(= x none)`, and reading the value is `(val x)`, which has no meaning when `x` is `none`.

To keep that safe, the value can only be read where a null check has been seen. The symbolic executor tracks, next to each variable, a **narrowed view**: after `if ($x !== null)` the view of `x` is the constant `nn@7 = (val x)`, a plain `Int` that arithmetic accepts. The variable itself keeps its nullable term, so that the two branches of an `if` can be merged back into one `ite` of the nullable type, and narrowing follows each path:

- an early `return` leaves only the other branch alive, so the rest of the method sees the narrowed view;
- when both branches narrow the same variable, the merge narrows it to the `ite` of the two views, which is how `if ($x === null) { $x = 5; }` leaves `$x` known to be non-null;
- an assignment forgets the view, and fields are forgotten with their object (`dto->field` entries go with `dto`).

The same narrowing runs inside a single expression, for `&&`, `||`, `?:`, `??` and the arms of `match (true)`, and inside `implies()`, where the conclusion is read under the premise. An arm of `match (true)` with several conditions runs when any one of them holds, like `||`, so only an arm with a single condition is narrowed by it; the arms after it are narrowed by every condition being false.

## Data classes

A readonly data class is a datatype with one constructor whose arguments are its `public readonly` properties, in order, and one accessor per property. Other properties, such as the mutable state of a framework base class, are not part of the datatype, and a read of one is rejected before anything is sent to the solver. `$cart->coupon->percent` is a chain of accessors, `new Receipt(a, b, c)` is the constructor, and the ranges that PHPDoc declares on properties become assumptions on every input of that class and goals on every returned one.

A class whose constructor contains logic cannot be built in a verified method, because the logic could throw and the translation would not see it. Its constructor must also be declared in the analysed file, for the reason given for [calls](#calls): the proof depends on the body of the constructor. The class can still be received as a parameter: an instance only exists if its constructor accepted it, so nothing more than the property types is assumed. That also needs every modelled property to be set in every instance, which is why only the properties promoted by a constructor that no subclass can skip (the class or the constructor is `final`) are part of the datatype.

The name of each datatype, constructor and accessor is derived from the PHP names so that two different names never meet in the script: `\` becomes `.`, bytes outside `[A-Za-z0-9_]` become `~` and two hex digits, and a class name and a case or property name are joined with `/`. `Order\Status` and `Order_Status` are the sorts `E_Order.Status` and `E_Order_Status`, and `A::B_C` and `A_B::C` the constructors `C_A/B_C` and `C_A_B/C`.

## Constants, `self` and `$this`

None of these adds a theory. `self::` and `static::` are resolved to the declaring class before translation, `static::` only where late binding cannot change the target. A class constant is read from PHPStan's reflection: its initializer is folded to a type, and a type with a single int, float, string, bool or enum case value becomes that literal in the script. No symbol is declared for it.

In an instance method of an enum or of a readonly data class, `$this` is declared as one more input, before the parameters, with the sort of the class and the domain of a parameter of that type. It is declared only when the method or its contracts use it, so other instance methods are encoded exactly as before. Its value cannot change during the call, which is why the contracts and the body read the same constant.

## Exceptions

A `throw` ends its path like a `return`, without a value. Its obligation is that its path condition implies `allowed(T)`, for the class `T` of the exception it builds. Each `#[Throws(D::class, when: w)]` is an "if and only if", so `allowed(T)` is false when no declaration covers `T`, and otherwise the conjunction of `w` for every declaration whose `D` covers `T` (its class, a parent class or an interface) and of `!w` for every declaration with a condition whose `D` does not cover `T`: where that condition holds, a `D` has to be thrown instead. A declaration without a condition adds nothing to the conjunction. In a branch of `match`, `?:` or `??`, the value of the branch is a fresh constant that nothing constrains, and the statement that holds the expression removes the paths that threw from the path condition before going on. That is why a `throw` is only accepted where the expression is the whole value of the statement: nothing else is computed from the fresh constant.

When the method can throw, every `#[Ensures]` is checked as `returns => contract`, and each `#[Throws]` with a condition adds one check after them: that no path returns while the condition holds. Together with `allowed(T)`, that check makes each declaration exact: when its condition holds the method throws, and what it throws is a `D`.

## Calls

A call to a method of the same file is inlined. The arguments are translated in the caller, the body of the callee is executed symbolically from the path condition of the call, with its parameters bound to the arguments and `$this` to the receiver, and the value of the call is the `ite` of its `return` statements. The callee's `#[Requires]` become obligations of the caller under that path condition, reported as `contracts.requiresViolated`, and every safety obligation inside the callee is stated for these arguments and reported at the line of the call, with the chain of calls that leads to it.

The method is the one PHP would run. For `$x->m()`, the class of `$x` must be final or the method final or private, and a private `m()` declared by the calling class wins over an `m()` of the subclass that `$x` is an instance of, as in PHP. PHP resolves a property the same way, so `$x->p` in a class that declares a private `$p`, on an instance of a subclass with its own `$p`, is rejected rather than read from the subclass. For `static::m()`, a private method does not count, because PHP looks the name up in the called class. An `int` argument becomes a `float` only for a parameter whose native type is `float`, which is the only conversion PHP makes in both typing modes. Nothing about the callee is assumed, not even its `#[Ensures]`, so a wrong contract on the callee cannot make the caller's proof wrong.

The method has to be in the same file because the proof depends on its body, and PHPStan re-analyses the dependents of a file only when its signatures change.

## The return type is a goal

After the body and the postconditions, one more check asks whether the returned value can leave the domain of the declared return type (a PHPDoc range, or the range of a property of a returned object). It runs after the `#[Ensures]` checks and is never assumed by them, so a bad value is first reported through the contract that states why it is bad, then as a violation of the type.

## Where the PHP semantics live

| PHP | SMT-LIB |
|---|---|
| `int` | `Int`, bounded by `[PHP_INT_MIN, PHP_INT_MAX]` by the obligations |
| `bool` | `Bool` |
| `float` | `Float64`, the IEEE 754 binary64 sort, with the rounding mode fixed to round-to-nearest-even. PHP's `===` is `fp.eq`, not SMT's `=`, so `NAN === NAN` is false and `0.0 === -0.0` is true. |
| `(float) $int` | `((_ to_fp 11 53) RNE (to_real x))` |
| `(int) $float` | `(sbv_to_int ((_ fp.to_sbv 64) RTZ x))`, truncating toward zero, behind a `contracts.invalidCast` check |
| `floor`, `ceil`, `sqrt`, `abs` | `fp.roundToIntegral RTN`, `fp.roundToIntegral RTP`, `fp.sqrt`, `fp.abs` |
| `string` | `String`, with the assumption that every character of an input is in `0..255`, so that a character is a byte |
| string literal | the same bytes, written with `\u{..}` escapes for anything that is not printable ASCII |
| `strlen`, `.`, `str_starts_with`, `str_ends_with`, `str_contains` | `str.len`, `str.++`, `str.prefixof`, `str.suffixof`, `str.contains` |
| an int in `.`, in an interpolation, or in `(string)` | `php_int_to_string`: `str.from_int`, with a `-` in front for a negative value |
| enum | an algebraic datatype with one constructor per case |
| `$enum->value`, `$enum->name` | functions defined by `ite` over the constructors |
| `Enum::from($v)`, `Enum::tryFrom($v)` | an `ite` over the backing values, with a `contracts.invalidEnumValue` check for `from()`, and a nullable result for `tryFrom()` |
| `?T` | a datatype `(none \| some T)` |
| readonly data class | a datatype with one constructor and one accessor per property |
| `intdiv($a, $b)` | `php_intdiv`: truncates toward zero, unlike SMT's `div` |
| `$a % $b` | `php_mod`: the sign follows the dividend, unlike SMT's `mod` |
| `match` | a chain of `ite` plus an exhaustiveness obligation |
| `min($a, $b)`, `max($a, $b)`, `abs($a)` on ints | `ite` |

The translation of the PHP semantics is compared with PHP itself by `tests/Solver/SemanticsTest.php`, bit for bit: `php_intdiv` and `php_mod` on pairs of operands with every combination of signs, the floating-point operations on random bit patterns and on the awkward values (`NAN`, the infinities, both zeros, the smallest subnormal, `PHP_FLOAT_MAX`, integers beyond 2^53), the conversions between `int` and `float`, and the string operations on strings with NUL bytes and non-ASCII bytes. See [Testing](testing.md).

## What runs where

| Step | Cost on the reference machine (Windows, PHP 8.5, Z3 5.4.1) |
|---|---|
| Parse contracts, translate to SMT-LIB, render the script | about 0.7 ms per method on average, 5 ms for the first one while classes load |
| Start Z3 through Symfony Process and run the checks | about 210 to 240 ms per method for `int`, `bool`, string, enum, nullable and data class methods |
| The same, for a method that does floating-point arithmetic | 0.2 to 4.7 seconds, depending on the operations |
| For comparison: `z3` run directly on the generated scripts | about 50 to 66 ms; a trivial script takes 62 ms |

The solver process dominates, and most of it is start-up rather than proving. Keeping a Z3 process alive between methods is the planned fix, see [Limits and roadmap](limits-and-roadmap.md#roadmap). Methods that Z3 cannot decide are the exception: they wait for the full `timeoutMs`.
