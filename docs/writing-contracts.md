# Writing contracts

## The attributes

```php
use PhpstanContracts\Attribute\Ensures;
use PhpstanContracts\Attribute\Pure;
use PhpstanContracts\Attribute\Requires;
```

| Attribute | Repeatable | Meaning |
|---|---|---|
| `#[Requires('expr')]` | yes | A precondition: the proof assumes it holds on entry. |
| `#[Ensures('expr')]` | yes | A postcondition: the proof shows it holds whenever the method returns. |
| `#[Pure]` | no | Opt the method in with no contract. Only the automatic safety checks run. |

The argument must be a plain string literal holding exactly one PHP expression. The string is parsed, never executed. A syntax error is reported as `contracts.invalidContract` on the attribute's line.

Each `#[Ensures]` is a separate proof. If three fail you get three errors, each at its own attribute, each with its own counterexample.

## Where the input domain comes from

A proof is only as strong as the set of inputs it covers. The extension builds that set from three sources:

| Source | Example | Notes |
|---|---|---|
| Native type | `int`, `float`, `string`, `?int`, an enum, a data class | See [Supported types](types.md). `int` means the full 64-bit PHP range, `float` includes `NAN` and the infinities. |
| PHPDoc range | `@param int<1, 365> $nights`, `@var int<0, 100> $percent` on a property | Taken as an assumption. PHPStan itself enforces it at call sites, see below. |
| `#[Requires]` | `#[Requires('$depositCents <= $paidCents')]` | Best for relations between parameters. |

PHPStan already checks PHPDoc ranges for the callers it can analyse, which is why they are a sound thing to assume. A controller that passes plain `int` values to `Pagination::offset()` gets:

```
ListingController.php:13: Parameter #1 $page of static method PhpstanContracts\Examples\RealWorld\Pagination::offset() expects int<1, 1000000>, int given.
ListingController.php:13: Parameter #2 $perPage of static method PhpstanContracts\Examples\RealWorld\Pagination::offset() expects int<1, 1000>, int given.
```

The caller has to establish the range, by validating the input or by declaring it on its own parameters, before the proof's assumption is true in production.

Prefer a PHPDoc range for a plain bound on one parameter, and `#[Requires]` for anything that relates two parameters. Never leave an `int` unbounded when the body multiplies it: the prover will find the overflow (see [the rate limiter example](real-world-examples.md#7-rate-limiting-the-prover-refuses-to-guess-your-input-range)).

## The contract language

A contract is a PHP expression that evaluates to `bool`. Inside `#[Ensures]`, `$result` is the returned value. Parameters always denote their value **on entry**, even if the body reassigns them.

Available in contracts:

| Construct | Example |
|---|---|
| int, float, string, bool and `null` literals; `PHP_INT_MAX`, `PHP_INT_MIN`, `NAN`, `INF`, `PHP_FLOAT_*` | `$result <= PHP_INT_MAX`, `$result === "EUR"` |
| arithmetic `+ - * %`, unary `-` | `$result * 10000 - $net * $rate` |
| `/` on floats | `$total / 2.0` |
| `.` between strings | `$result === $a . "/" . $b` |
| `intdiv($a, $b)`, `min($a, $b)`, `max($a, $b)`, `abs($a)` | `abs($result - $x) <= 5000` |
| `strlen()`, `str_starts_with()`, `str_ends_with()`, `str_contains()` | `strlen($result) <= 20` |
| `floor()`, `ceil()`, `sqrt()`, `is_nan()`, `is_infinite()`, `is_finite()`, `(int)`, `(float)` | `is_finite($result)` |
| comparisons `< <= > >= == === != !== <>` | `$result >= 0` |
| `&&`, `\|\|`, `!`, `and`, `or` | `$a > 0 && $b > 0` |
| `cond ? a : b` | `$flexible ? $paid : 0` |
| `match` | `match ($zone) { ... }` |
| `??`, `?->`, `isset()`, `is_null()` | `($customerPercent ?? 0)`, `$cart->coupon?->minimumCents` |
| enum cases, `->value`, `->name`, `::from()`, `::tryFrom()` | `$result === Currency::Usd`, `$result === $tier->value` |
| `self::` and `static::` for the class that declares the method | `$result === self::EndOfLife` |
| class constants holding an int, float, string, bool or enum case | `$result < ErpCode::RANGE_END`, `self::OFFSET + $webId` |
| `$this` in a method of an enum or a readonly data class | `implies($this === StockStatus::InStock, $result)`, `$this->onHand` |
| property reads on a data class, `new` | `$result->totalCents`, `$cart->coupon->percent` |
| `implies($premise, $conclusion)`, contracts only | `implies($year % 400 === 0, $result)` |

PHP has no implication operator, so `implies($a, $b)` is provided. It means `!$a || $b`, and the conclusion is read under the premise: `implies($x !== null, $x > 0)` is accepted even when `$x` is nullable.

**Contract arithmetic on `int` is mathematical.** In a contract, `$a * $b` is the true product, with no 64-bit limit, as in Dafny or Why3. Only the method body is held to PHP's `int` range. This is what lets you write `$result * $perPage >= $totalItems` without worrying about the multiplication in the contract itself overflowing.

**Contract arithmetic on `float` is not.** A float expression in a contract is computed in IEEE 754, exactly as in the body, so `$result === $a + ($b + $c)` is a statement about rounding.

**Class names in strings.** A contract string cannot see the file's `use` statements. A short name such as `OrderStatus` is resolved first against the enums and data classes in the method signature, then against the method's namespace, then globally. Use a fully qualified name for anything else. `self` and `static` mean the class that declares the method, with the same rules as in the body (see [Class constants, `self` and `$this`](#class-constants-self-and-this)).

**`$this` in a contract** is the object the method is called on. It cannot change during the call, so it means the same thing in `#[Requires]` and in `#[Ensures]`.

**Quotes.** The contract is a single-quoted PHP string, so write the string literals inside it with double quotes: `#[Ensures('$result === "EUR"')]`.

## The supported PHP subset

The translator is a whitelist. A construct that is not listed is rejected with a message, never ignored. That is what keeps a verified method pure.

### Types

`int`, `bool`, `float`, `string`, enums, readonly data classes, and a nullable version of each. See [Supported types](types.md) for what each can do.

An unsupported type reports `Type list<int> is not supported in verified methods (supported: int, bool, float, string, enums, readonly data classes, and nullable versions of these).` on the parameter, or on the return type.

Variadic and by-reference parameters are rejected. A parameter named `$result` is rejected because the name is reserved for `#[Ensures]`.

### Statements

| Supported | Notes |
|---|---|
| `$x = expr;` `$x += expr;` `$x -= expr;` `$x *= expr;` `$x .= expr;` `$x ??= expr;` | Local variables only. A variable keeps its type, except that a nullable variable can receive a value or `null`. |
| `if` / `elseif` / `else` | A variable assigned in only one branch is not usable after the `if`. Null checks narrow the types inside the branches. |
| `return expr;` | Early returns are fine. |

| Rejected | Message |
|---|---|
| `while`, `for`, `foreach`, `do` | `Loops are not supported: termination and loop invariants cannot be established.` |
| `switch` | `switch is not supported; use match.` |
| `try` / `catch` | `try/catch is not supported: verified methods must not rely on exceptions.` |
| `throw` | `throw is not supported yet: express the valid input domain with #[Requires] instead.` |
| `$x++`, `--$x` | ``Increment and decrement are not supported; write `$x += 1` or `$x -= 1`.`` |
| `echo`, `global`, `static`, `unset` | `Side effects and global or static state are forbidden in verified methods.` |
| assignment to a property, array item or variable variable | `Only assignments to local variables are allowed (no property, array or dynamic writes).` |
| a bare `return;` | `A bare return is not supported: verified methods must return a value.` |
| a variable that changes type | `Variable $x changes type from int to bool.` |
| `$x = null;` for a variable with no type yet | `Cannot infer a type for $x from null; assign a value of the type it will hold, or give a nullable parameter.` |
| a variable read before it is defined on every path | `Variable $x is not defined at this point.` |

### Expressions

Everything in the table in [the contract language](#the-contract-language) works in the body too, except `implies()`. These are rejected:

| Rejected | Message |
|---|---|
| `$a / $b` on two ints | `The "/" operator yields a float when the division is not exact; use intdiv().` |
| `$a ** $b`, `<=>`, bitwise operators | `Operator "**" is not part of the verified subset.` |
| `$a ?: $b` | `The short ternary "?:" relies on truthiness; write the condition explicitly.` |
| `if ($count)` on an `int` | `Expected a bool expression but got int (implicit truthiness is not supported).` |
| `$x == null`, `$a == 'x'` on strings | `Comparing with null using == is true for 0, "" and false; use === null.` and `Strings compared with == are compared numerically when both look like numbers; use ===.` |
| `$int === 1.0` | `int === float is always false in PHP; the operands have different types.` |
| `$a === $b` on two objects | `Objects are compared by identity with ===; compare their fields instead.` |
| `(string)`, `(bool)`, `(array)` casts | `Only (int) and (float) casts are supported.` |
| `min()` or `max()` on floats | `min() and max() are only supported on ints: PHP orders NaN and mixed int/float arguments in a way that is not modelled.` |
| `round()` and any function other than those listed | `Call to round() is not part of the verified subset: purity cannot be established.` |
| method calls | `Method calls and instantiations are not part of the verified subset yet: side effects cannot be ruled out.` |
| `new` on a class that is not a plain data class | `Cannot instantiate Percentage: its constructor contains logic, which is not verified.` |
| `$this` in a class that is not an enum or a readonly data class | `Object state ($this) is only part of the verified subset in enums and readonly data classes.`, followed by the reason, such as `Property Counter::$count must be public and readonly` |
| a method call on `$this` | `Method calls and instantiations are not part of the verified subset yet: side effects cannot be ruled out.` |
| reading a property that is not `public readonly`, or whose type is not supported | `Property Foo::$_additional must be public and readonly: only immutable data classes are supported.` |
| `static::LIMIT` on a class that is not final, for a constant that is not final | `static::LIMIT is late bound: a subclass of CartLimits can override the constant. Use self::LIMIT, or make the class or the constant final.` |
| `static::` for anything else on a class that is not final, such as `new static()` | `static:: is late bound and CartLimits is not final, so a subclass can change what it refers to; use self:: or make the class final.` |
| `parent::` | `parent:: is not supported; write the name of the parent class.` |
| a constant holding an array, or a value that PHPStan cannot fold | `Constant Rules::CODES has type array<int, int>; only constants holding a single int, float, string, bool or enum case are supported.` |
| a private or protected constant that the class under verification cannot read | `Constant Foo::SECRET is not accessible here.` |
| `Foo::class` | `Class name constants (::class) are not part of the verified subset.` |
| `$$name`, `$f()` | `Variable variables ($$name) are forbidden in verified methods.` and `Dynamic function calls are forbidden in verified methods.` |
| `$obj->$prop` | `Dynamic property access ($obj->$prop) is forbidden in verified methods.` |
| arrays | `Arrays are not supported yet.` |
| a nullable value used as a number, string or float | `Expected a number (int or float) but got ?int, which may be null here: check it first with `!== null`, `isset()` or `??`.` |

`min()` and `max()` take exactly two `int` arguments. `match` needs at least one arm, and without a `default` arm it must be exhaustive (see below).

Instance methods of any class are fine as long as they never touch `$this`. In an enum or a readonly data class, `$this` is supported, see below. A method that reads `$this->state` on a class with mutable state is rejected.

### Class constants, `self` and `$this`

These need no new reasoning from the solver, only name resolution, and they are what most enum and value-object code is written with.

**`self::` and `static::`** resolve to the class that declares the method, in the body and in contracts: `return self::Immediate;` in an enum, `self::tryFrom($code)`, `new self(...)` in a data class. `static::` is late bound, so it only means that class when no subclass can change it: always in an enum, and in a class declared `final`. A `@final` PHPDoc tag does not count, because it does not stop a subclass at runtime. `parent::` is rejected.

**A class constant** is inlined as a literal when PHPStan folds its value to a single int, float, string, bool or enum case. That includes constants computed from other constants, such as `const SIZE = self::RANGE_END - self::OFFSET;`. The value comes from the constant's initializer, and a `float` constant written as an int literal holds the float, as in PHP:

```php
public const int OFFSET = 98_000_000;

public const int RANGE_END = 99_000_000;
```

```php
#[Ensures('implies($result !== null, $result >= 98000000 && $result < 99000000)')]
#[Ensures('implies($result !== null, $result >= self::OFFSET && $result < ErpCode::RANGE_END)')]
public static function tryFrom(int $webId): ?int
{
    return $webId >= 0 && $webId < self::RANGE_END - self::OFFSET
        ? self::OFFSET + $webId
        : null;
}
```

The off-by-one version is refuted at the last id:

```
ErpCode.php:34: Postcondition #[Ensures('implies($result !== null, $result < self::RANGE_END)')] can be violated.
Counterexample: $webId = 1000000 => $result = 99000000 (return at line 37).
```

`self::X` and `ClassName::X` are statically bound, so they are always accepted. `static::X` is accepted when the class is final or the constant is declared `final`. Otherwise a subclass can override the constant, and the access is rejected:

```php
/** Rejected: `static::` is late bound and MAX_ITEMS can be overridden. */
#[Ensures('$result <= 50')]
public static function itemsLateBound(int $items): int
{
    return min($items, static::MAX_ITEMS);
}
```

```
CartLimits.php:22: static::MAX_ITEMS is late bound: a subclass of PhpstanContracts\Examples\CartLimits can override the constant.
Use self::MAX_ITEMS, or make the class or the constant final.
```

**`$this` in an enum** is one of the cases. An enum instance method is a function of an implicit parameter of the enum type, so `$this === self::InStock`, `match ($this)` with its exhaustiveness check, `$this->value` and `$this->name` all work. **`$this` in a readonly data class** is a record of its properties, read with `$this->onHand`, under the same conditions as a parameter of that class (see [Readonly data classes](types.md#readonly-data-classes)). In both cases `$this` is printed first in a counterexample, like a parameter. A call to another method on `$this`, such as `$this->isFinal()`, is still a method call and is rejected.

## What is checked automatically

Besides your contracts, every checked method must satisfy a set of safety conditions. PHP integers are not mathematical integers, so these are not optional extras: without them a proof about `$a * $b` would be wrong the moment the product wraps into a float.

| Condition | Raised when | Identifier |
|---|---|---|
| No integer overflow | `+`, `-`, `*`, unary `-`, `abs()` or `intdiv()` can leave `PHP_INT_MIN..PHP_INT_MAX` | `contracts.overflow` |
| No division by zero | the divisor of `intdiv()`, `%` or a float `/` can be `0` | `contracts.divisionByZero` |
| No unhandled match | a `match` without `default` can receive a value that no arm covers | `contracts.unhandledMatch` |
| Every path returns | some path reaches the end of the method without `return` | `contracts.missingReturn` |
| Valid enum value | `Status::from($v)` can receive a value that is not a backing value | `contracts.invalidEnumValue` |
| Representable cast | `(int) $float` can receive `NAN`, an infinity, or a value outside the `int` range | `contracts.invalidCast` |
| Return type respected | the returned value can leave the domain of its declared type, such as a PHPDoc range or a field range of a data class | `contracts.returnType` |

Each condition is checked **under the path that reaches it**. In `$parts > 0 && intdiv($total, $parts) > 3` the division is only checked where `$parts > 0`, so the guard is honoured. The same holds for the arms of `?:`, `??` and `match`.

`intdiv()` and `%` follow PHP, not SQL or SMT: the quotient truncates toward zero and the remainder takes the sign of the dividend.

## Diagnostics

| Identifier | Meaning | Usual fix |
|---|---|---|
| `contracts.ensuresViolated` | An `#[Ensures]` can be false. The message carries a counterexample. | Fix the code, or the contract if the counterexample is a legitimate input. |
| `contracts.overflow` | An operation can leave the PHP `int` range. | Bound the inputs with a PHPDoc range or `#[Requires]`. |
| `contracts.divisionByZero` | A divisor can be zero. | Bound it away from zero, or guard the division. |
| `contracts.unhandledMatch` | A `match` can throw `UnhandledMatchError`. | Add the missing arm or a `default`. |
| `contracts.missingReturn` | A path ends without returning. | Return on every path. |
| `contracts.invalidEnumValue` | `from()` can throw `ValueError`. | Use `tryFrom()` for untrusted input. |
| `contracts.invalidCast` | `(int)` of a float that can be `NAN`, infinite or out of range. | Bound the float, or exclude `NAN` and infinities with `#[Requires]`. |
| `contracts.returnType` | The method can return a value that its declared type excludes. | Fix the computation, or widen the declared type. |
| `contracts.contradictoryRequires` | No input satisfies the parameter types and `#[Requires]`, so every proof would be empty. | Loosen the preconditions. |
| `contracts.unknown` | Z3 answered `unknown` within `timeoutMs`. Nothing is proven or refuted. | See [when Z3 gives up](#when-z3-gives-up). |
| `contracts.unsupported` | The method leaves the supported subset. | Rewrite it, or extract the pure rule into a smaller method. |
| `contracts.invalidContract` | A contract is not a string literal, is not valid PHP, or names a class that cannot be resolved. | Fix the attribute. |
| `contracts.solver` | Z3 could not be started, timed out as a whole, or answered something unexpected. | Check `z3Binary`. |

## Reading a counterexample

```
Pagination.php:33: Postcondition #[Ensures('$totalItems === 0 || ($result - 1) * $perPage < $totalItems')]
can be violated. Counterexample: $totalItems = 100000000, $perPage = 1 => $result = 100000001 (return at line 36).
```

- The error is reported on the line of the attribute that failed.
- The values before `=>` are the parameters on entry. These are real inputs: you can paste them into a test. In an enum or data class method, `$this` comes first: `$this = StockStatus::TemporarilyUnavailable`.
- The value after `=>` is what the method returns for them, and `(return at line N)` is the `return` statement taken.
- Z3 tends to pick values at the edge of the declared ranges, which is usually where the bug is.
- Safety diagnostics (overflow, division by zero, unhandled match, missing return) show the inputs only, since the method does not return normally.

Values are printed as PHP:

| Type | Printed as |
|---|---|
| `float` | `0.30000000000000004`, `1.0E+25`, `NAN`, `INF`, `-INF`, `-0.0` |
| `string` | `'.jpg'`, or `"\xC2\xA3"` when it holds bytes that are not printable ASCII |
| enum | `Currency::Gbp` |
| nullable | `null` or the value |
| data class | `Cart(subtotalCents: 0, coupon: null, currency: Currency::Usd)`, nested ones included |

## When Z3 gives up

Linear integer arithmetic is decidable, and Z3 settles it quickly. Multiplying two variables is **non-linear**, and non-linear integer arithmetic is undecidable in general. Most products between bounded variables are fine, as the examples show, but some statements make Z3 answer `unknown`:

```
SolverLimits.php:22: Not proven: Z3 answered "unknown" after 30000 ms for #[Ensures('$result % $perPage === 0')].
```

When that happens:

1. **Bound every variable that is multiplied.** Ranges are what make products tractable.
2. **State the property as an identity.** `$result % $perPage === 0` makes Z3 reason about a remainder by a variable. Proving `$result === $perPage * ($page - 1)` says the same thing and is proved at once (see `Pagination::offset`).
3. **Split a conjunction** into several `#[Ensures]`. Each is a separate query.
4. **Lower `timeoutMs`** while iterating so that a hopeless query fails fast.

Floating point has its own limits. The same answer appears when two float variables are multiplied, and the cost of every float operation is much higher than an integer one, division more than multiplication. See [What is slow](types.md#what-is-slow).
