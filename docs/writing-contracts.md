# Writing contracts

## The attributes

```php
use PhpstanContracts\Attribute\Ensures;
use PhpstanContracts\Attribute\Pure;
use PhpstanContracts\Attribute\Requires;
use PhpstanContracts\Attribute\Throws;
```

| Attribute | Repeatable | Meaning |
|---|---|---|
| `#[Requires('expr')]` | yes | A precondition: the proof assumes it holds on entry. |
| `#[Ensures('expr')]` | yes | A postcondition: the proof shows it holds whenever the method returns. |
| `#[Pure]` | no | Opt the method in with no contract. Only the automatic safety checks run. |
| `#[Throws(E::class, when: 'expr')]` | yes | The method throws `E` exactly when `expr` holds on entry. Without `when`, it may throw `E` on any path. See [Exceptions](#exceptions). |

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
| `.` between strings and ints, interpolation, `(string)` of an int | `$result === $a . "/" . $b`, `$result === "Ships in $days days"` |
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
| `in_array($x, [a, b, c])`, `in_array($x, [a, b, c], true)` over a list literal or a class constant holding one | `in_array($this, [self::Administrator, self::Validator])` |
| `implies($premise, $conclusion)`, contracts only | `implies($year % 400 === 0, $result)` |

PHP has no implication operator, so `implies($a, $b)` is provided. It means `!$a || $b`, and the conclusion is read under the premise: `implies($x !== null, $x > 0)` is accepted even when `$x` is nullable.

**Contract arithmetic on `int` is mathematical.** In a contract, `$a * $b` is the true product, with no 64-bit limit, as in Dafny or Why3. Only the method body is held to PHP's `int` range. This is what lets you write `$result * $perPage >= $totalItems` without worrying about the multiplication in the contract itself overflowing.

**Contract arithmetic on `float` is not.** A float expression in a contract is computed in IEEE 754, exactly as in the body, so `$result === $a + ($b + $c)` is a statement about rounding.

**Class names in strings.** A contract string cannot see the file's `use` statements. A short name such as `OrderStatus` is resolved first against the enums and data classes in the method signature, then against the method's namespace, then globally. Use a fully qualified name for anything else. `self` and `static` mean the class that declares the method, with the same rules as in the body (see [Class constants, `self` and `$this`](#class-constants-self-and-this)).

**`$this` in a contract** is the object the method is called on. It cannot change during the call, so it means the same thing in `#[Requires]` and in `#[Ensures]`.

**Quotes.** The contract is a single-quoted PHP string, so write the string literals inside it with double quotes: `#[Ensures('$result === "EUR"')]`.

## The supported PHP subset

The translator is a whitelist. A construct that is not listed is rejected with a message, never ignored. That is what keeps a verified method pure.

The messages in the tables below are real, with one abbreviation: most messages print a class by its fully qualified name, and a row that shows a short name, such as `Foo` or `CartLimits`, stands for it. The `Cannot instantiate ...` messages and the enum names that start a message, such as `Flag` or `Limit::tryFrom()`, are printed as shown.

### Types

`int`, `bool`, `float`, `string`, enums, readonly data classes, and a nullable version of each. See [Supported types](types.md) for what each can do.

An unsupported type reports `Type list<int> is not supported in verified methods (supported: int, bool, float, string, enums, readonly data classes, and nullable versions of these).` on the parameter, or on the return type.

Variadic and by-reference parameters are rejected. A parameter named `$result` is rejected because the name is reserved for `#[Ensures]`.

A parameter that is `float` in PHPDoc only, such as an untyped `$x` or a `mixed $x` with `@param float $x`, is rejected: PHPStan lets a caller pass an `int` there, and PHP converts an `int` to a `float` by the native type only, so the `int` stays an `int`. The message is `Parameter $x has the PHPDoc type float but the native type mixed, which is what PHP converts its argument to: an int argument stays an int. Declare float in the signature.`

A contract on a method without a body, in an interface or on an abstract method, is rejected: there is nothing to verify, and the methods that implement it do not inherit the contract. The message is `Contracts on abstract and interface methods are not verified, and the methods that implement them do not inherit them: put the contract on each concrete method.` A contract on a property hook is rejected the same way, with `Contracts on property hooks are not verified: move the logic to a method and put the contract there.`

### Statements

| Supported | Notes |
|---|---|
| `$x = expr;` `$x += expr;` `$x -= expr;` `$x *= expr;` `$x .= expr;` `$x ??= expr;` | Local variables only. A variable keeps its type, except that a nullable variable can receive a value or `null`. |
| `if` / `elseif` / `else` | A variable assigned in only one branch is not usable after the `if`. Null checks narrow the types inside the branches. |
| `return expr;` | Early returns are fine. |
| `throw new E(...);`, and `throw` as a whole branch of `match`, `?:` or `??` whose value is returned or assigned | See [Exceptions](#exceptions). |

| Rejected | Message |
|---|---|
| `while`, `for`, `foreach`, `do` | `Loops are not supported: termination and loop invariants cannot be established.` |
| `switch` | `switch is not supported; use match.` |
| `try` / `catch` | `try/catch is not supported: verified methods must not rely on exceptions.` |
| `throw` inside an operation or a condition | `throw is only supported as a statement, or as a whole branch of match, ?: or ?? whose value is returned or assigned.` |
| `yield` or `yield from` anywhere in the body, even where no path reaches it | `Generators are not supported: a yield anywhere in the body, even in unreachable code, makes the method return a Generator.` |
| `throw $exception`, `throw self::error()` | ``Only `throw new Exception(...)` is supported: the exception must be built where it is thrown.`` |
| an exception class with its own constructor | `Cannot throw InvalidWebId: its constructor is not the one of Exception or Error, so it can run code that is not verified.` |
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
| `(bool)`, `(array)` casts | `Only (int), (float) and (string) casts are supported.` |
| a float or a bool in `.` or in an interpolation | `Converting a float to a string is not supported: only strings and ints can be concatenated or interpolated.` |
| `(string)` of a float, a bool or a nullable value | `(string) of a float is not supported: only ints and strings are converted.` |
| `min()` or `max()` on floats | `min() and max() are only supported on ints: PHP orders NaN and mixed int/float arguments in a way that is not modelled.` |
| `round()` and any function other than those listed | `Call to round() is not part of the verified subset: purity cannot be established.` |
| an unqualified call to a supported function, such as `abs()` or `in_array()`, in a namespace that declares a function of that name | `Call to PhpstanContracts\Tests\Fixtures\Shadowing\abs() is not part of the verified subset: purity cannot be established.` |
| an unqualified supported constant, such as `PHP_INT_MAX` or `NAN`, in a namespace that declares a constant of that name | `Constant PhpstanContracts\Tests\Fixtures\Shadowing\PHP_INT_MAX is not part of the verified subset: PHP reads it instead of the built-in constant.` |
| any other constant, including a supported one written in another case, such as `php_int_max` | `Constant php_int_max is not part of the verified subset.` |
| a call to a method declared in another file | `PhpstanContracts\Tests\Fixtures\Point::sum() is declared in another file. Only methods of the same file can be called: ...` (see [Calls to other methods](#calls-to-other-methods)) |
| a call that PHP binds at runtime to a method a subclass can override | `PhpstanContracts\Tests\Fixtures\OpenShape::sides() can be overridden: PhpstanContracts\Tests\Fixtures\OpenShape is not final, so a subclass can replace the method. Make the class or the method final, or the method private.` |
| `static::k()` on a class that is not final, for a method that is not final, private or not | `PhpstanContracts\Tests\Fixtures\ShadowParent::k() can be overridden: PhpstanContracts\Tests\Fixtures\ShadowParent is not final, so a subclass can declare its own k(), which static:: calls instead, even though this one is private. Use self::, or make the class or the method final.` |
| a called method whose parameter has no native type | `Parameter $value of PhpstanContracts\Tests\Fixtures\CallFixture::isOne() has no native type, so PHP passes its argument as it is; declare the type in the signature.` |
| a called method whose parameter has a native type that does not match its PHPDoc type | `Parameter $value of PhpstanContracts\Tests\Fixtures\CallFixture::isOneOfMixed() has the native type mixed, which is what PHP converts its argument to, but the PHPDoc type float; make them agree.` |
| a called method marked `#[\Deprecated]` | `PhpstanContracts\Tests\Fixtures\CallFixture::deprecated() is #[\Deprecated]: calling it triggers E_USER_DEPRECATED, which runs the error handler.` |
| a recursive call | `Recursive calls are not supported: Tree::depth() is already being translated.` |
| a method call in a contract | `Method calls are not supported in contract expressions yet.` |
| a method call on anything but an enum case or a data object | `Method calls are only supported on enums and data classes, not on a string.` |
| `new` on a class that is not a plain data class | `Cannot instantiate Percentage: its constructor contains logic, which is not verified.` |
| `new` on a data class whose constructor is declared in another file | `Cannot instantiate Receipt: its constructor is declared in another file. Only classes whose constructor is in the same file can be built: PHPStan re-analyses a file when the signatures it uses change, not when a body in another file does, so the proof would not be redone.` |
| `new` on a class whose constructor is private or protected and not accessible from the verified method | `Cannot instantiate Sealed: its constructor is not accessible here.` |
| `new` on a class whose constructor is marked `#[\Deprecated]` | `Cannot instantiate OldStyle: its constructor is #[\Deprecated], and calling it triggers E_USER_DEPRECATED, which runs the error handler.` |
| `new` on a class with a destructor, its own or inherited | `Cannot instantiate Noisy: its destructor runs code that is not verified.` |
| `$this` in a class that is not an enum or a readonly data class | `Object state ($this) is only part of the verified subset in enums and readonly data classes.`, followed by the reason, such as `Property Counter::$count must be public and readonly` |
| reading a property that is not `public readonly`, or whose type is not supported | `Property Foo::$state must be public and readonly: only immutable data classes are supported.` |
| reading a `public readonly` property that is not promoted by the constructor | `Property PhpstanContracts\Tests\Fixtures\LazyInit::$x may be uninitialized: only the properties promoted by the constructor are known to be set in every object.` |
| reading a promoted property of a class that is not final and whose constructor is not final | `Property PhpstanContracts\Tests\Fixtures\OpenPoint::$x may be uninitialized: PhpstanContracts\Tests\Fixtures\OpenPoint is not final and neither is its constructor, so a subclass constructor can skip the one that sets it. Make the class or the constructor final.` |
| reading a property promoted by the constructor of a parent class, in a class that declares its own constructor | `Property PhpstanContracts\Tests\Fixtures\Origin::$x may be uninitialized: it is promoted by the constructor of PhpstanContracts\Tests\Fixtures\OpenPoint, which the constructor of PhpstanContracts\Tests\Fixtures\Origin does not have to call.` |
| reading a property that is `float` in PHPDoc only | `Property PhpstanContracts\Tests\Fixtures\MixedMeasure::$amount cannot be read in a verified method: its PHPDoc type is float but its native type is mixed, so an int assigned to it stays an int. Declare float natively.` |
| `$c->x` in a class that declares a private `$x`, on an instance of a subclass that declares its own `$x` | `From PhpstanContracts\Tests\Fixtures\PropertyShadowParent, $c->x reads the private property PhpstanContracts\Tests\Fixtures\PropertyShadowParent::$x, not PhpstanContracts\Tests\Fixtures\PropertyShadowChild::$x: PHP picks the private property of the calling class. Rename one of the two properties.` |
| `static::LIMIT` on a class that is not final, for a constant that is not final | `static::LIMIT is late bound: a subclass of CartLimits can override the constant. Use self::LIMIT, or make the class or the constant final.` |
| `static::` for anything else on a class that is not final, such as `new static()` | `static:: is late bound and CartLimits is not final, so a subclass can change what it refers to; use self:: or make the class final.` |
| `parent::` | `parent:: is not supported; write the name of the parent class.` |
| a constant holding an array, or a value that PHPStan cannot fold | `Constant Rules::CODES has type array<int, int>; only constants holding a single int, float, string, bool or enum case are supported.` |
| a private or protected constant that the class under verification cannot read | `Constant Foo::SECRET is not accessible here.` |
| a constant or an enum case marked `#[\Deprecated]`, or a constant whose initializer reads one | `Constant PhpstanContracts\Tests\Fixtures\DeprecatedLimits::LIMIT is deprecated: reading it triggers a deprecation notice, which runs the error handler.` and `Enum case PhpstanContracts\Tests\Fixtures\Mode::Legacy is deprecated: ...` |
| a constant, or a string backing value, whose initializer turns a float into a string, such as `'v' . (0.1 + 0.2)`, or orders a float and a string, such as `(0.1 + 0.2) < '0.3!'` (also `<=`, `>`, `>=`, `<=>`) | `An initializer in PhpstanContracts\Tests\Fixtures\ConstantInitializers converts a float to a string, whose digits depend on the precision ini setting, which is not modelled.` |
| a constant, or a backing value, whose initializer converts a float to an int implicitly: a float operand of `\|`, `&`, `^`, `~`, `<<`, `>>` or `%`, or a float array key or offset, such as `1.5 \| 0` or `self::NAMES[1.5]` | `An initializer in PhpstanContracts\Tests\Fixtures\ConstantInitializers converts a float to an int implicitly, which runs the error handler unless the float holds an integer in the range of int.` |
| the same conversion in the initializer of a global constant that a constant or a backing value reads, such as `const PRINTED = 'v' . (0.1 + 0.2);` and `public const LABEL = PRINTED;` | `The initializer of constant PhpstanContracts\Tests\Fixtures\PRINTED converts a float to a string, whose digits depend on the precision ini setting, which is not modelled.` |
| `from()`, `tryFrom()` or `->value` of a backed enum with a backing value that PHPStan cannot fold, such as `case Huge = PHP_INT_MAX;` | `Limit::tryFrom() is not supported: The backing value of PhpstanContracts\Tests\Fixtures\Limit::Huge cannot be folded to a literal; write it as a literal.` |
| `from()` or `tryFrom()` on a pure enum that does not declare one | `Flag is not a backed enum, so it has no from().` |
| `Foo::class` | `Class name constants (::class) are not part of the verified subset.` |
| `$$name`, `$f()` | `Variable variables ($$name) are forbidden in verified methods.` and `Dynamic function calls are forbidden in verified methods.` |
| `$obj->$prop` | `Dynamic property access ($obj->$prop) is forbidden in verified methods.` |
| arrays, other than the list of `in_array()` | `Arrays are not supported yet.` |
| `in_array()` without `strict: true` on a string or a nullable value | `in_array() without strict: true compares with ==, which juggles a string; pass true as the third argument.` |
| `in_array()` on anything but a list literal or a constant holding one | `in_array() is only supported on a list<T>, or on a list literal such as [self::A, self::B] or a class constant holding one.` |
| a nullable value used as a number, string or float | `Expected a number (int or float) but got ?int, which may be null here: check it first with `!== null`, `isset()` or `??`.` |

A supported function is the built-in one. As in PHP, an unqualified call such as `abs($x)` in a namespace that declares its own `abs()` calls that function, so it is rejected, in the body and in contracts alike. `\abs($x)` is always the built-in one, and so is an unqualified call when the namespace declares no function of that name. The same holds for the supported constants: an unqualified `PHP_INT_MAX` in a namespace that declares its own `PHP_INT_MAX` reads that constant and is rejected, while `\PHP_INT_MAX` is always the built-in one. Constant names are case-sensitive, except `true`, `false` and `null`. The check sees the declarations that PHPStan knows: a file that it neither analyses nor scans, such as when one file is analysed alone, can declare a function or a constant of the namespace without the check seeing it, so the fully qualified form is the one that does not depend on the files analysed (see [what a green run does not guarantee](limits-and-roadmap.md#what-it-does-not-guarantee)).

`min()` and `max()` take exactly two `int` arguments. `match` needs at least one arm, and without a `default` arm it must be exhaustive (see below).

`in_array($x, [a, b, c], true)` is translated as `$x === a || $x === b || $x === c`, which is what PHP computes. Without `true`, PHP compares with `==`, so the needle must be an `int`, a `float`, a `bool` or an enum case, where `==` is exact: `1 == 1.0` is true, as in PHP, and enum cases compare by identity. A string or a nullable needle needs `strict: true`, because `"1e1" == "10"` and `null == 0` are true. The haystack is a list literal, whose elements can be any supported expression, or a class constant such as `self::VALIDATORS` whose value PHPStan folds to a list of scalars or enum cases.

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
ErpCode.php:36: Postcondition #[Ensures('implies($result !== null, $result < self::RANGE_END)')] can be violated.
Counterexample: $webId = 1000000 => $result = 99000000 (return at line 39).
```

PHPStan folds a constant in its own process, so some initializers are rejected, because a run of the code can see another value or none: one that reads a `#[\Deprecated]` constant or enum case, which from PHP 8.4 on triggers a deprecation and runs the error handler (the attribute is checked whatever version of PHP runs PHPStan), and one that turns a float into a string, such as `'v' . (0.1 + 0.2)`, whose digits depend on the `precision` ini setting. An ordering between a float and a string, `<`, `<=`, `>`, `>=` or `<=>`, is rejected for the same reason: PHP 8 orders a float and a non-numeric string by printing the float, so `(0.1 + 0.2) < '0.3!'` is true at the default precision and false at `precision=-1`. `==` and `!=` between them are accepted, because a finite float prints as a numeric string, which never equals a non-numeric one, and `INF` and `NAN` print the same at every precision. A float converted to an int implicitly, such as the operand of `1.5 | 0` or the offset of `self::NAMES[1.5]`, is rejected too: from PHP 8.1 on, it runs the error handler unless the float holds an integer in the range of int. A global constant read by an initializer, such as `public const LABEL = PRINTED;`, is checked for the float-to-string conversion in its own initializer, the `const` statement or top-level `define()` that defines it, and so are the constants that this initializer reads; a deprecation or an implicit float-to-int conversion in there does not matter, because a global constant is evaluated where it is defined, not where it is read. When that definition cannot be read, such as a constant defined by a PHPStan bootstrap file, whose value PHPStan takes from the running process, the read is rejected: `The initializer of constant PhpstanContracts\Tests\Fixtures\DEFINED_AT_RUNTIME cannot be checked for a float converted to a string: no file defines it.` The same holds for reading a `#[\Deprecated]` enum case in the body. PHPStan's result cache does not record the attributes of the enum cases of another file, so adding `#[\Deprecated]` to such a case is only seen once the file that reads it is analysed again.

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

**`$this` in an enum** is one of the cases. An enum instance method is a function of an implicit parameter of the enum type, so `$this === self::InStock`, `match ($this)` with its exhaustiveness check, `$this->value` and `$this->name` all work. **`$this` in a readonly data class** is a record of its properties, read with `$this->onHand`, under the same conditions as a parameter of that class (see [Readonly data classes](types.md#readonly-data-classes)). In both cases `$this` is printed first in a counterexample, like a parameter. A call to another method on `$this`, such as `$this->isFinal()`, works when that method is declared in the same file, see below.

### Calls to other methods

A method can call another method **declared in the same file**: a helper of the same class, `$this->level()` in an enum, or a getter of a data class. The call is translated by translating the body of the callee, with its parameters bound to the arguments and `$this` to the object the method is called on. The proof sees exactly what the callee computes, so the callee does not need a contract of its own, and its safety checks are made for the arguments of this call:

```php
#[Ensures('$result >= 1 && $result <= 4')]
public function hierarchyLevel(): int
{
    return match ($this) {
        self::Administrator => 1,
        self::Validator => 2,
        self::Prescriber => 3,
        self::Viewer => 4,
    };
}

#[Ensures('implies($this === self::Administrator, $result)')]
#[Ensures('implies($this === $other, $result)')]
#[Ensures('implies($other === self::Administrator, $result === ($this === self::Administrator))')]
public function canManage(Role $other): bool
{
    return $this->hierarchyLevel() <= $other->hierarchyLevel();
}
```

With `<` instead of `<=`, a role can no longer manage itself, and the counterexample says which one:

```
Role.php:41: Postcondition #[Ensures('implies($this === $other, $result)')] can be violated.
Counterexample: $this = Role::Validator, $other = Role::Validator => $result = false (return at line 44).
```

**The `#[Requires]` of the callee are checked at the call.** A call that can break one is reported as `contracts.requiresViolated`, with the values of the caller's inputs:

```
CallFixture.php:45: The call to PhpstanContracts\Tests\Fixtures\CallFixture::halve() can violate its precondition #[Requires('$x >= 0')].
Counterexample: $x = -1.
```

The same check in [`examples/RealWorld/DeliveryLabel.php`](../examples/RealWorld/DeliveryLabel.php): `plural()` requires `$days >= 2`, `shortDelay()` calls it only for more than one day and is proven, and `shortDelayBroken()` guards with `>= 1`:

```
DeliveryLabel.php:76: The call to PhpstanContracts\Examples\RealWorld\DeliveryLabel::plural() can violate its precondition #[Requires('$days >= 2')]. Counterexample: $days = 1.
```

A safety check inside the callee is reported at the line of the call, and says which call it comes from: ``Possible integer overflow: `$x * 2` can leave the PHP int range, in the call to PhpstanContracts\Tests\Fixtures\CallFixture::double().`` When the callee calls another method, the message names the whole chain, from the call on the reported line to the method that holds the check: ``..., in the call to PhpstanContracts\Tests\Fixtures\CallFixture::quadruple(), through PhpstanContracts\Tests\Fixtures\CallFixture::double().``

What can be called:

| Call | Condition |
|---|---|
| `self::helper($x)`, `ClassName::helper($x)` | A static method. The call is bound at compile time. |
| `$this->level()`, `$other->level()`, `$cart->coupon->total()` | The object is an enum case or a data object. PHP picks the method from the runtime class, so the class must be `final` (an enum always is), or the method `final` or `private`. A `private` method is the one of the class whose code makes the call: from a class that declares a private `m()`, `$object->m()` runs that `m()` even when the object is a subclass with a public `m()` of its own, and so does the proof. |
| `static::helper($x)` | `static::` looks the method up in the called class, where a subclass's own `helper()` wins even over a private one. So the class must be `final`, or the method `final` and not `private`. |
| arguments | Positional or named. A missing argument takes its default value. An `int` is converted for a parameter whose native type is `float` or `?float`, as PHP does. PHP converts by the native type only, so a parameter without a native type, or whose native type does not match its PHPDoc type, is rejected. Any other mismatch of types is rejected. |

The callee must itself be in the verified subset; when it is not, the reason is given: ``The call to PhpstanContracts\Tests\Fixtures\CallFixture::loops() cannot be verified: Loops are not supported: ...``. Recursion, methods from a trait, methods marked `#[\Deprecated]` (calling one triggers `E_USER_DEPRECATED`, which runs the error handler), generators (``PhpstanContracts\Tests\Fixtures\Generators::five() contains a yield, so calling it returns a Generator.``) and calls inside a contract are rejected.

A called method cannot throw yet: ``The call to PhpstanContracts\Tests\Fixtures\ThrowsFixture::guarded() cannot be verified: throw is not supported in a called method yet: ...``.

**Why the same file.** PHPStan's result cache re-analyses a file when it changes, and the files that depend on it only when a signature they use changes. A proof that read the body of a method in another file would not be redone when that body changes, and could stay green for code that is now wrong. The same holds for `new X(...)`, whose proof relies on the body of the constructor being empty: X's constructor must be declared in the same file. Calls to methods of other files need modular verification, where the caller relies on the callee's contract, which is on the [roadmap](limits-and-roadmap.md#roadmap).

## Exceptions

A guard that throws is the usual way to protect a method from an input it cannot handle. Moving the guard into a `#[Requires]` would change the method: it would no longer defend itself. `#[Throws]` keeps the guard and states when it fires:

```php
#[Throws(\InvalidArgumentException::class, when: '$webId < 0 || $webId >= self::SIZE')]
#[Ensures('$result >= self::OFFSET && $result < self::OFFSET + self::SIZE')]
#[Ensures('$result === self::OFFSET + $webId')]
public static function fromWebId(int $webId): int
{
    if ($webId < 0 || $webId >= self::SIZE) {
        throw new \InvalidArgumentException('The web id is outside the reserved range.');
    }

    return self::OFFSET + $webId;
}
```

The declaration means **"throws `E` if and only if the condition holds on entry"**, and both directions are proven:

- a `throw new T(...)` is reachable only where the condition of every `#[Throws]` that covers `T` (its class, a parent class or an interface) holds, and where the condition of every `#[Throws]` that does not cover `T` is false, since there that other exception must be thrown. A `throw` that no `#[Throws]` covers must be unreachable. Otherwise it is reported as `contracts.unexpectedThrow`, with an input that reaches it;
- when the condition holds, the method does not return. Otherwise it is reported as `contracts.throwsViolated`, with an input and the value returned.

So several declarations keep their meaning side by side. With `#[Throws(\LogicException::class)]` and `#[Throws(\InvalidArgumentException::class, when: '$x < 0')]`, an `InvalidArgumentException` is thrown exactly when `$x < 0`, and another `LogicException`, such as a `DomainException`, only when `$x >= 0`.

The report of `contracts.unexpectedThrow` spells out where the declarations allow the `throw`. Below, a `RangeException` thrown for every `$x < 10` breaks the other declaration, since an `InvalidArgumentException` must be thrown when `$x < 0`:

```
ThrowsFixture.php:252: `throw new \RangeException('Small.')` can be reached where the #[Throws] declarations do not allow it:
they allow it only where #[Throws(RangeException::class, when: '$x < 10')] holds and #[Throws(InvalidArgumentException::class, when: '$x < 0')] does not hold.
Counterexample: $x = -1.
```

A `throw` of a class that no `#[Throws]` covers is reported as "can be reached where no #[Throws] allows it".

The `#[Ensures]` are proven for the paths that return, which is what they describe. The guard with `>` instead of `>=` lets the first id past the range through, and both checks say so:

```
ThrowsFixture.php:34: #[Throws(InvalidArgumentException::class, when: '$webId < 0 || $webId >= self::SIZE')] is violated:
the method can return although the condition holds. Counterexample: $webId = 1000000 => $result = 99000000 (return at line 42).
ThrowsFixture.php:35: Postcondition #[Ensures('$result < self::OFFSET + self::SIZE')] can be violated.
Counterexample: $webId = 1000000 => $result = 99000000 (return at line 42).
```

**A `throw` that no `#[Throws]` declares must be unreachable.** This is how a consistency check, such as a `default` arm of a `match` that cannot happen, is proven dead. `ErpCode::shard()` in [`examples/RealWorld/ErpCode.php`](../examples/RealWorld/ErpCode.php) is proven:

```php
#[Requires('$webId >= 0 && $webId < 1000')]
#[Ensures('$result >= 1 && $result <= 2')]
#[Ensures('implies($webId < 500, $result === 1)')]
public static function shard(int $webId): int
{
    return match (intdiv($webId, 500)) {
        0 => 1,
        1 => 2,
        default => throw new \LogicException('Unknown shard.'),
    };
}
```

`shardBroken()` has the same body with a domain that grew to 1500 ids, so the `default` arm can be reached:

```
ErpCode.php:98: `throw new \LogicException('Unknown shard.')` can be reached where no #[Throws] allows it. Counterexample: $webId = 1000.
```

Without `when`, `#[Throws(E::class)]` allows `E` on any path where no other declaration forbids it, and proves nothing about when it happens: only the paths that return are checked.

What a `throw` can be:

| Form | Condition |
|---|---|
| `throw new E('message', 3);` | A statement. `E` is a concrete `Throwable` whose constructor is the one of `Exception` or `Error`, which only stores its arguments: the built-in exceptions and the classes that extend them without a constructor of their own. The message must be a string, the code an int, the previous exception `null`. The arguments are translated like any expression. |
| `$value ?? throw new E()`, `$ok ? $x : throw new E()`, `default => throw new E()` | A whole branch of `??`, `?:` or `match`, whose value is returned or assigned to a variable. The path that throws stops there, so nothing is computed with the missing value. |

A `throw` inside an operation, such as `($n ?? throw new E()) + 1`, or in a condition, is rejected: the rest of the expression would be computed on a path that has already ended. `try`/`catch` is still rejected, so an exception always leaves the method.

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
| Existing list key | `$list[$i]` can read a key that does not exist: PHP warns and yields `null` | `contracts.undefinedKey` |
| Return type respected | the returned value can leave the domain of its declared type, such as a PHPDoc range or a field range of a data class | `contracts.returnType` |
| No undeclared exception | a `throw` can be reached where the [`#[Throws]`](#exceptions) declarations do not allow it | `contracts.unexpectedThrow` |
| Callee preconditions respected | a [call to a method of the same file](#calls-to-other-methods) can break one of its `#[Requires]` | `contracts.requiresViolated` |

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

| `contracts.invalidCast` | `(int)` of a float that can be `NAN`, infinite or out of range: PHP 8.3 and 8.4 wrap the value or return 0, PHP 8.5 also raises a warning. | Bound the float, or exclude `NAN` and infinities with `#[Requires]`. |
| `contracts.undefinedKey` | A list read `$list[$i]` whose key may not exist. | Guard the read with `$i >= 0 && $i < count($list)` or `isset($list[$i])`, or state the range with `#[Requires]`. |
| `contracts.returnType` | The method can return a value that its declared type excludes. | Fix the computation, or widen the declared type. |
| `contracts.unexpectedThrow` | A `throw` can be reached where no `#[Throws]` allows it, or where the `#[Throws]` declarations do not allow it; the message then lists the conditions that allow it. | Declare it with its condition, or fix the guard. |
| `contracts.throwsViolated` | The method can return although the condition of a `#[Throws]` holds. | Fix the guard, or the condition. |
| `contracts.requiresViolated` | A call to a method of the same file can break one of the callee's `#[Requires]`. | Guard the call, or establish the precondition first. |
| `contracts.contradictoryRequires` | No input satisfies the parameter types and `#[Requires]`, so every proof would be empty. | Loosen the preconditions. |
| `contracts.unknown` | Z3 answered `unknown` within `timeoutMs`. Nothing is proven or refuted. On the method line, it means Z3 could not tell whether any input satisfies the preconditions, so the proofs of the method may be vacuous. | See [when Z3 gives up](#when-z3-gives-up). |
| `contracts.unsupported` | The method leaves the supported subset. | Rewrite it, or extract the pure rule into a smaller method. |
| `contracts.invalidContract` | A contract is not a string literal, is not valid PHP, or names a class that cannot be resolved. | Fix the attribute. |
| `contracts.solver` | Z3 could not be started, timed out as a whole, answered something unexpected, or reported an error in the script, which would otherwise drop an assertion in silence. | Check `z3Binary`. An error in the script is a bug of the extension: please report it. |

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
