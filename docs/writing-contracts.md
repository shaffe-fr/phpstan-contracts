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
| `in_array($x, $list, true)` over a `list<T>` (a variable, a property or a call result) | `in_array($sku, $blockedSkus, true)` |
| `count($list)` | `count($result) <= count($amounts) + 1` |
| `array_sum($list)` over a `list<int>` | `array_sum($result) === $discountCents` |
| `$list[$i]` with an `int` index; the read must be proven to exist | `count($amounts) > 0 && $amounts[0] >= 0`, `$result[0] >= 0` |
| `isset($list[$i])`, `$list[$i] ?? $default` | `implies(isset($items[$i]), $result)` |
| `$shape['key']` with a string literal key of the shape; write the key with double quotes inside the attribute | `$result["net"] + $result["vat"] === $grossCents` |
| list literals `[]`, `[$a, $b]`, `[...$list, $x]`, and shape literals `['net' => $n, 'vat' => $v]` | `$result === [1, 2]` |
| `===` and `!==` on two lists of ints, bools, strings or enum cases (or their nullable versions) | `$result === $items` |
| `implies($premise, $conclusion)`, contracts only | `implies($year % 400 === 0, $result)` |

PHP has no implication operator, so `implies($a, $b)` is provided. It means `!$a || $b`, and the conclusion is read under the premise: `implies($x !== null, $x > 0)` is accepted even when `$x` is nullable.

**Contract arithmetic on `int` is mathematical.** In a contract, `$a * $b` is the true product, with no 64-bit limit, as in Dafny or Why3. Only the method body is held to PHP's `int` range. This is what lets you write `$result * $perPage >= $totalItems` without worrying about the multiplication in the contract itself overflowing.

**`array_sum()` in a contract is mathematical too.** It is the true sum of the elements, with no 64-bit limit and no float: `array_sum($result) === $discountCents` says that the parts add up to the discount. In the body, `array_sum()` is PHP's, which becomes a float when a partial sum leaves the `int` range, so the extension checks that it does not (see [Loops](#loops)).

**Contract arithmetic on `float` is not.** A float expression in a contract is computed in IEEE 754, exactly as in the body, so `$result === $a + ($b + $c)` is a statement about rounding.

**Class names in strings.** A contract string cannot see the file's `use` statements. A short name such as `OrderStatus` is resolved first against the enums and data classes in the method signature, then against the method's namespace, then globally. Use a fully qualified name for anything else. `self` and `static` mean the class that declares the method, with the same rules as in the body (see [Class constants, `self` and `$this`](#class-constants-self-and-this)).

**`$this` in a contract** is the object the method is called on. It cannot change during the call, so it means the same thing in `#[Requires]` and in `#[Ensures]`.

**Quotes.** The contract is a single-quoted PHP string, so write the string literals inside it with double quotes: `#[Ensures('$result === "EUR"')]`.

## The supported PHP subset

The translator is a whitelist. A construct that is not listed is rejected with a message, never ignored. That is what keeps a verified method pure.

The messages in the tables below are real, with one abbreviation: most messages print a class by its fully qualified name, and a row that shows a short name, such as `Foo` or `CartLimits`, stands for it. The `Cannot instantiate ...` messages and the enum names that start a message, such as `Flag` or `Limit::tryFrom()`, are printed as shown.

### Types

`int`, `bool`, `float`, `string`, enums, readonly data classes, `list<T>`, array shapes with string keys, and a nullable version of each. See [Supported types](types.md) for what each can do.

An unsupported type reports `Type object is not supported in verified methods (supported: int, bool, float, string, enums, readonly data classes, lists, array shapes with string keys, and nullable versions of these).` on the parameter, or on the return type.

Variadic and by-reference parameters are rejected. A parameter named `$result` is rejected because the name is reserved for `#[Ensures]`.

A parameter that is `float` in PHPDoc only, such as an untyped `$x` or a `mixed $x` with `@param float $x`, is rejected: PHPStan lets a caller pass an `int` there, and PHP converts an `int` to a `float` by the native type only, so the `int` stays an `int`. The message is `Parameter $x has the PHPDoc type float but the native type mixed, which is what PHP converts its argument to: an int argument stays an int. Declare float in the signature.`

A contract on a method without a body, in an interface or on an abstract method, is rejected: there is nothing to verify, and the methods that implement it do not inherit the contract. The message is `Contracts on abstract and interface methods are not verified, and the methods that implement them do not inherit them: put the contract on each concrete method.` A contract on a property hook is rejected the same way, with `Contracts on property hooks are not verified: move the logic to a method and put the contract there.`

### Statements

| Supported | Notes |
|---|---|
| `$x = expr;` `$x += expr;` `$x -= expr;` `$x *= expr;` `$x .= expr;` `$x ??= expr;` | Local variables only. A variable keeps its type, except that a nullable variable can receive a value or `null`. |
| `$x++;` `++$x;` `$x--;` `--$x;` | As a statement, on an `int` or a `float` variable: the same as `$x += 1` or `$x -= 1`, with the same overflow check. Inside a larger expression (`$y = $x++`), it is rejected. |
| `if` / `elseif` / `else` | A variable assigned in only one branch is not usable after the `if`. Null checks narrow the types inside the branches. |
| `return expr;` | Early returns are fine. |
| `foreach ($list as $item)`, `foreach ($list as $i => $item)` | Over a `list<T>`, by value. The body can hold everything above, `break`, `continue` and `return`. See [Loops](#loops). |
| `break;`, `continue;`, `break 2;`, `continue 2;` | A literal level that does not exceed the number of `foreach` around the statement. |
| `throw new E(...);`, `throw E::factory(...);`, and `throw` as a whole branch of `match`, `?:` or `??` whose value is returned or assigned | See [Exceptions](#exceptions). |

| Rejected | Message |
|---|---|
| `while`, `for`, `do` | `Only foreach over a list is supported: a while, do or for loop needs a termination argument, which is not supported yet. Iterate the list with foreach ($list as $i => $item), or read it with count($list) and $list[$i].` |
| `foreach ($list as &$item)`, `foreach ($list as $k => &$item)` | `foreach by reference (&$item) changes the list in place and leaves $item a reference to its last element, which is not modelled. Iterate by value and build a new list with $out[] = $value.` |
| `foreach ($list as [$a, $b])`, `foreach ($list as list($a, $b))` | `Destructuring in foreach is not supported: iterate with foreach ($list as $item) and read the elements, such as $item['net'].` |
| a key or a value of `foreach` that is not a local variable, such as `$this->x` | `The key and the value of a foreach must be local variables.` |
| the same variable as key and value | `The key and the value of this foreach are both $x; use two different variables.` |
| `foreach` over an array shape | `foreach over an array shape is not supported: its keys are not a list and its values can have different types. Read each key, such as $totals['net'].` |
| `foreach` over a nullable list | `foreach over $amounts, which may be null here: PHP warns "foreach() argument must be of type array|object" and skips the loop. Check it first with !== null.` |
| `foreach` over anything but a list, such as a string | `foreach is only supported over a list<T>, not over a string.` |
| `foreach` over `[]`, or over a variable that is always `[]` | `This list is always empty, so the loop never runs; remove the loop.` |
| `break 3;` with fewer loops around it, `continue 2;` in a single loop | `break 3 leaves more loops than enclose it here (1).` |
| `break $n;` | `break needs a literal level, such as break 2.` |
| `break 0;` | `break 0 is not valid: the level of break starts at 1.` |
| `break;` or `continue;` outside a `foreach` | `break is only supported inside a foreach.` |
| a `foreach` that would be unrolled more than `contracts.maxLoopUnroll` times | `This foreach would be unrolled 500 times, more than contracts.maxLoopUnroll (100). Bound the list more tightly with #[Requires], or raise contracts.maxLoopUnroll.` |
| nested `foreach` whose product of bounds exceeds `contracts.maxLoopUnroll` | `These nested loops would be unrolled 20 × 20 = 400 times, more than contracts.maxLoopUnroll (100). Bound the lists more tightly with #[Requires], extract the inner loop into a smaller method of this file, or raise contracts.maxLoopUnroll.` |
| a variable first assigned in the loop and read after it | `Variable $seen is not defined at this point.` (the list can be empty, and the loop then never runs) |
| `array_sum()` of a `list<float>` | `array_sum() of a list<float> is not supported: it returns the int 0 for an empty list and a float otherwise. Add the elements in a foreach, starting from 0.0.` |
| `array_sum()` of any other list, such as `list<?int>` or `list<bool>`, or of a shape | `array_sum() is only supported on a list<int>: PHP converts null, bools and numeric strings to numbers, which is not modelled. Add the elements in a foreach.` |
| `switch` | `switch is not supported; use match.` |
| `try` / `catch` | `try/catch is not supported: verified methods must not rely on exceptions.` |
| `throw` inside an operation or a condition | `throw is only supported as a statement, or as a whole branch of match, ?: or ?? whose value is returned or assigned.` |
| `yield` or `yield from` anywhere in the body, even where no path reaches it | `Generators are not supported: a yield anywhere in the body, even in unreachable code, makes the method return a Generator.` |
| `throw $exception`, or any expression that is neither `new` nor a static call | ``Only `throw new Exception(...)` and `throw Exception::factory(...)` with a static factory are supported: the exception must be built where it is thrown, not taken from a variable or another expression.`` |
| `throw $factory->make()` | ``throw of the result of a method call is not supported: the class of the object is not known here. Use a static factory, `throw Exception::factory(...)`, or `throw new Exception(...)`.`` |
| `throw $class::make()`, `throw E::$method()` | ``The class and the method of an exception factory must be written by name: `throw $class::make()` and `throw Exception::$method()` are not supported.`` |
| `throw new $class(...)`, an anonymous class | ``The class of a thrown exception must be written by name: `new $class(...)` and anonymous classes are not supported.`` |
| `throw E::factory()` where `factory()` is not static | ``FactoryRangeException::instance() is not a static method, so it needs an object that is not known here. Make it static, or build the exception with `new`.`` |
| `throw E::factory()` where `factory()` is abstract, or declared on an interface | `FactoryRangeException::make() is abstract, so the body that runs is not known here. Call a concrete factory, or build the exception with `new`.` |
| `throw E::factory()` where `factory()` is private or protected and out of reach | `FactoryRangeException::hidden() is not accessible here.` |
| `throw E::factory()` where the method does not exist | `FactoryRangeException has no method missing().` |
| a factory with no native return type, or one that returns an interface, a union, a nullable type or `never` | `FactoryRangeException::noType() returns no declared type, which is not a single exception class. Declare the exception class, `self` or `static` as its native return type (an interface, a union, a nullable type, `never` and a missing type are not supported).` |
| a factory that returns a class that is not an exception | `FactoryRangeException::notAnException() returns stdClass, which is not an exception class: declare a class that implements Throwable as its native return type.` |
| a factory whose return class is not final, with a `#[Throws]` that has a `when` condition on a subclass | `FactoryBaseException::make() returns FactoryBaseException, which is not final: the object can be an instance of a subclass, and a #[Throws] with a `when` condition on FactorySpecificException depends on the class. Make FactoryBaseException final, or declare a final class as the return type.` |
| a factory that carries `#[Requires]`, `#[Throws]` or `#[\Deprecated]` | `FactoryContractException::guarded() carries #[Requires]: the body of an exception factory is not inspected, so a precondition would not be checked at this call, and a factory that throws or triggers a deprecation does not return the exception that the proof assumes. Build the exception with `new`, or remove the attribute.` |
| an argument of a factory that does not fit its parameter | `Argument $value of FactoryRangeException::atLeast() is float, but the parameter is int.` |
| an exception class with its own constructor | `Cannot throw InvalidWebId: its constructor is not the one of Exception or Error, so it can run code that is not verified.` |
| `$x++` on a string, null or bool variable (`"a"++` is `"b"`, not `"a" + 1`) | `Increment and decrement are only supported on an int or a float variable, not on a string.` |
| `echo`, `global`, `static`, `unset` | `Side effects and global or static state are forbidden in verified methods.` |
| assignment to a property or a variable variable | `Only assignments to local variables are allowed (no property, array or dynamic writes); appending is supported on a list built in the method ($list = []; $list[] = $value), or write $list = [...$list, $value].` |
| `$list[$i] = $x`, `$list[$i] += $x` | `Only appending to a list ($list[] = $value) is supported: writing $list[$i] can add a key out of order, which turns the list into another kind of array.` |
| `$shape['key'] = $x` | `Writing to an array shape is not supported: build a new shape literal, such as ['net' => $net, 'vat' => $vat].` |
| `$a[$i][$j] = $x`, `$a[$i][] = $x` | `Nested writes such as $a[$i][$j] = ... are not supported: only appending to a local list variable ($list[] = $value) is.` |
| `$items[] = $x` where `$items` is a parameter, or a copy of one | `Appending to $items, a list received from outside the method, is not supported: PHP appends at the array's next free key, which can be past count($items) after an unset() by the caller, so the result may not be a list. Write $items = [...$items, $value] instead, which always builds a list.` |
| `$l[] = $x` on a variable that is not defined yet | `Appending to $missing, which is not defined at this point, would create an array; start with $missing = [].` |
| `$shape[] = $x` | `Appending to an array shape is not supported: build a new shape literal.` |
| `[$a, $b] = $list`, `list($a, $b) = $list` | `Destructuring ([$a, $b] = ...) is not supported: read the elements with $list[0] and $list[1], or with their key, such as $shape['net'].` |
| `unset($list[$i])` | `unset() is not supported: removing an element leaves a gap in the keys of a list, which is then no longer a list. Build the list without that element, with a literal or with $list[] = $value.` |
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
| `$this` in a class with an instance property, of any visibility, inherited or from a trait | `Object state ($this) is only part of the verified subset in enums, readonly data classes and classes without instance properties. Class App\Counter has the instance property $count, so $this has state.`, followed by `It cannot be modelled as data:` and the reason, which starts with `Type App\Counter is not supported: ...` and then names the property, such as `Property App\Counter::$count must be public and readonly` |
| `$this` compared, read for a property or used as a value in a class without instance properties | `Class App\Guard has no instance property, so $this has no state to model: use it only as the receiver of a call, such as $this->helper(). Comparing it, passing it as an argument and reading a property of it are not supported.` |
| `$this` passed as an argument, such as `self::measure($this)` | `Type App\Guard is not supported: only enums, readonly data classes, lists, array shapes with string keys and scalars are. App\Guard has no properties to model.`, because the parameter has the type of a class without state |
| `$this->helper()` in a class that is not final, where `helper()` is neither `final` nor `private` | `App\Guard::helper() can be overridden: App\Guard is not final, so a subclass can replace the method. Make the class or the method final, or the method private.` |
| reading a property that is not `public readonly`, or whose type is not supported | `Property Foo::$state must be public and readonly: only immutable data classes are supported.` |
| a parameter or a property whose type is a class that has no property that can be modelled | `Type Counter is not supported: only enums, readonly data classes, lists, array shapes with string keys and scalars are. Property Counter::$count must be public and readonly: only immutable data classes are supported.` For a data class, the `public readonly` properties of an unsupported type come first, with their type and reason, see [Readonly data classes](types.md#readonly-data-classes). |
| reading a `public readonly` property that is not promoted by the constructor | `Property PhpstanContracts\Tests\Fixtures\LazyInit::$x may be uninitialized: only the properties promoted by the constructor are known to be set in every object.` |
| reading a promoted property of a class that is not final and whose constructor is not final | `Property PhpstanContracts\Tests\Fixtures\OpenPoint::$x may be uninitialized: PhpstanContracts\Tests\Fixtures\OpenPoint is not final and neither is its constructor, so a subclass constructor can skip the one that sets it. Make the class or the constructor final.` |
| reading a property promoted by the constructor of a parent class, in a class that declares its own constructor | `Property PhpstanContracts\Tests\Fixtures\Origin::$x may be uninitialized: it is promoted by the constructor of PhpstanContracts\Tests\Fixtures\OpenPoint, which the constructor of PhpstanContracts\Tests\Fixtures\Origin does not have to call.` |
| reading a property that is `float` in PHPDoc only | `Property PhpstanContracts\Tests\Fixtures\MixedMeasure::$amount cannot be read in a verified method: its PHPDoc type is float but its native type is mixed, so an int assigned to it stays an int. Declare float natively.` When it is the only property of its class, the class is rejected as a type and the message starts with `Type PhpstanContracts\Tests\Fixtures\MixedMeasure is not supported: ...` and then lists it, see [Readonly data classes](types.md#readonly-data-classes). |
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
| `array`, `array<string, int>`, `non-empty-array<int, int>` as a type | `Type array<string, int> is not supported in verified methods: an array with arbitrary keys is not modelled. Use list<T> for a sequence, or an array shape with string keys such as array{net: int, vat: int}.` |
| a union element type, `list<float\|string>` | `Type list<float\|string> is not supported in verified methods: its elements have type float\|string, and a list must hold a single supported type (int, bool, float, string, an enum, a readonly data class, an array shape, or a nullable version of these).` |
| a list of lists, `list<list<int>>` | `Type list<list<int>> is not supported yet: a list of lists is not modelled. Use a list of data classes or of array shapes.` |
| an optional key, `array{net: int, vat?: int}` | `Type array{net: int, vat?: int} is not supported: the optional key vat may be missing, which is not modelled. Make the key required, with a nullable value if it can be absent.` |
| a tuple or an int key, `array{int, string}` | `Type array{int, string} is not supported: array shapes are only supported with string keys. Use array{amount: int, label: string} or a data class.` |
| the empty shape `array{}` | `Type array{} is not supported: declare list<T> for a list that may be empty.` |
| a list or a shape of floats as an input | `Type list<float> is not supported for an input: PHP does not convert the values of an array and PHPStan accepts an int for a float in a list, so an element may hold an int. Use int (for example cents) or a list of data classes with a native float property.` |
| an array literal with int keys, or with string keys that PHP converts to ints, such as `'1'` | `Integer keys, and string keys that PHP converts to integers such as '1', are not supported in array literals: write a list without keys, or use non-numeric string keys.` |
| a literal mixing keys and no keys, `[1, 'a' => 2]` | `An array literal is either a list without keys, such as [1, 2], or a shape with string keys, such as ['net' => 1]; mixing the two is not supported.` |
| a duplicate key in a literal | `Key 'net' appears twice in this array literal; PHP keeps the last value. Write it once.` |
| elements of different types, `[1, 'a']`, `[1, 2.0]` | `The elements of this list have different types (int and string); a list must hold a single type. Convert the elements first, such as (float) $x, or use an array shape with a key per value.` |
| a nested list literal `[[1], [2]]` | `A list of lists is not supported yet: nested lists are not modelled. Use a list of data classes or of array shapes.` |
| `[...$shape]`, a spread of anything but a list | `Only a list<T> can be spread in a list literal, not `$shape` (array{net: int, vat: int}): PHP keeps the keys of other arrays, which are not 0..n-1. Spread a list, or build the array with its keys.` |
| `$list['a']`, `$list[$float]`, `$list[$nullable]` | `The index of a list must be an int, not a string: PHP converts some strings and floats to int keys, which is not modelled. Use an int index; for a float, check it and cast it with (int).` |
| `$shape[$k]` with a key that is not a literal | `The key of an array shape must be a string literal naming one of its keys, such as $totals['net'].` |
| `$shape['gross']` for a key the shape does not have | `array{net: int, vat: int} has no key 'gross'; its keys are 'net' and 'vat'.` |
| `$shape->net` | `An array shape is read with ['net'], not ->net.` |
| `$string[0]` | `Reading [...] is only supported on lists and array shapes, not on a string; string offsets are not supported, use str_starts_with() or strlen().` |
| `[][0]` | `This list is always empty, so reading from it always reads a missing key; append to the list before reading it, or check count() first.` |
| `$a == $b`, `!=`, `<>` with an array operand | `Arrays compared with == are compared loosely, with type juggling on every element ([1] == [true], ['1e1'] == ['10']); use === on lists, or compare the elements.` |
| `$a === $b` on two shapes, or on lists of shapes | `Array shapes compared with === also compare the order of their keys and any extra keys, which the type array{...} does not fix; compare the elements instead, such as $a['net'] === $b['net'].` |
| `$a === $b` on lists of floats | `Lists of floats cannot be compared with ===: PHP first checks whether both are the same array in memory, so [NAN] === [NAN] is false while $a === $a is true for $a = [NAN]. Compare count() and the elements instead, such as count($a) === count($b) && $a[0] === $b[0].` |
| `$a === $b` on lists of data classes | `Lists of objects cannot be compared with ===: objects are compared by identity; compare the fields of the elements.` |
| `<`, `<=`, `>`, `>=` on arrays | `Arrays cannot be ordered with <: PHP compares them by count, then key by key, which is not modelled. Compare count($list) or the elements.` |
| `count()` of a shape, `count($list, COUNT_RECURSIVE)` | `count() of an array shape is not supported: the type array{...} does not rule out extra keys. count() is supported on lists.` and `count() takes exactly one argument here; COUNT_RECURSIVE is not supported. Write count($list).` |
| `empty($list)`, `if ($list)` | `empty() relies on truthiness; write count($list) === 0.` |
| `isset()` or `??` through a list element, such as `$lines[$i]['net'] ?? 0` | ``?? on `$lines[$i]['net']` reads through the list element `$lines[$i]`, which is not modelled: PHP treats a missing element in the middle of the chain as unset. Check `isset($lines[$i])` first, or read `$lines[$i]['net']` into a variable.`` |
| any other array function, such as `array_map()`, `array_product()`, `sort()`, `implode()`, `is_array()` | `Call to array_product() is not part of the verified subset: of the array functions, only count(), in_array() and array_sum() are supported. Visit the elements with foreach instead.` |
| `in_array()` without `strict: true` on a string or a nullable value | `in_array() without strict: true compares with ==, which juggles a string; pass true as the third argument.` |
| `in_array()` on anything but a list, a list literal or a constant holding one | `in_array() is only supported on a list<T>, or on a list literal such as [self::A, self::B] or a class constant holding one.` |
| `in_array()` on a shape, or on a list of objects or shapes | `in_array() is only supported on a list or a list literal, not on an array shape; compare the values, such as $x === $totals['net'] || $x === $totals['vat'].` and `in_array() on a list of objects compares them by identity with strict: true and property by property without it, which is not modelled; compare a field instead.` |
| `in_array()` with a needle of another type than the elements | `in_array() needs a needle of the element type of the list: got float for a list<int>. Convert the needle first, such as (float) $x.` |
| a nullable value used as a number, string or float | `Expected a number (int or float) but got ?int, which may be null here: check it first with `!== null`, `isset()` or `??`.` |

A supported function is the built-in one. As in PHP, an unqualified call such as `abs($x)` in a namespace that declares its own `abs()` calls that function, so it is rejected, in the body and in contracts alike. `\abs($x)` is always the built-in one, and so is an unqualified call when the namespace declares no function of that name. The same holds for the supported constants: an unqualified `PHP_INT_MAX` in a namespace that declares its own `PHP_INT_MAX` reads that constant and is rejected, while `\PHP_INT_MAX` is always the built-in one. Constant names are case-sensitive, except `true`, `false` and `null`. The check sees the declarations that PHPStan knows: a file that it neither analyses nor scans, such as when one file is analysed alone, can declare a function or a constant of the namespace without the check seeing it, so the fully qualified form is the one that does not depend on the files analysed (see [what a green run does not guarantee](limits-and-roadmap.md#what-it-does-not-guarantee)).

`min()` and `max()` take exactly two `int` arguments. `match` needs at least one arm, and without a `default` arm it must be exhaustive (see below).

`in_array($x, [a, b, c], true)` is translated as `$x === a || $x === b || $x === c`, which is what PHP computes. Without `true`, PHP compares with `==`, so the needle must be an `int`, a `float`, a `bool` or an enum case, where `==` is exact: `1 == 1.0` is true, as in PHP, and enum cases compare by identity. A string or a nullable needle needs `strict: true`, because `"1e1" == "10"` and `null == 0` are true. The haystack is a list literal, whose elements can be any supported expression, or a class constant such as `self::VALIDATORS` whose value PHPStan folds to a list of scalars or enum cases.

Instance methods of any class are fine as long as they never touch `$this`. In an enum or a readonly data class, `$this` is supported, see below. In a class without any instance property, `$this` is supported as the receiver of a call, see [Calls to other methods](#calls-to-other-methods). A method that reads `$this->state` on a class with state is rejected.

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

**`$this` in an enum** is one of the cases. An enum instance method is a function of an implicit parameter of the enum type, so `$this === self::InStock`, `match ($this)` with its exhaustiveness check, `$this->value` and `$this->name` all work. **`$this` in a readonly data class** is a record of its properties, read with `$this->onHand`, under the same conditions as a parameter of that class (see [Readonly data classes](types.md#readonly-data-classes)). In both cases `$this` is printed first in a counterexample, like a parameter. A call to another method on `$this`, such as `$this->isFinal()`, works when that method is declared in the same file, see below. **`$this` in a class without any instance property** has no state to model, so it is accepted only as the receiver of such a call, see [Calls to other methods](#calls-to-other-methods).

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

**A service without state** is a `final` class with no instance property, such as `examples/RealWorld/ReconciliationDeltaGuard.php`: its methods call each other through `$this`, and there is nothing of `$this` to prove, so each call is translated with the body of its callee. Static properties and class constants do not count as state.
```php
#[Ensures('implies($ledgerCents === $bankCents, $result)')]
#[Ensures('implies($result, abs($ledgerCents - $bankCents) <= 100)')]
#[Ensures('implies(abs($ledgerCents - $bankCents) <= 100, $result)')]
public function isMatched(int $ledgerCents, int $bankCents): bool
{
    return $this->withinTolerance($this->deltaCents($ledgerCents, $bankCents));
}
```
`isMatchedBroken()` passes the signed difference instead, so a bank line far below the ledger line counts as matched:
```
ReconciliationDeltaGuard.php:39: Postcondition #[Ensures('implies($result, abs($ledgerCents - $bankCents) <= 100)')] can be violated.
Counterexample: $ledgerCents = 99999899, $bankCents = 100000000 => $result = true (return at line 42).
```
A class with any instance property is rejected, even a `private readonly` dependency: the proof would have to model it, and a promoted constructor service is not data. `$this` itself, compared or passed as an argument, is rejected too, with a reason that says to use it as the receiver of a call. In a class that is not final, the same rule as for any call applies: only a `final` or `private` method can be called through `$this`.
A safety check inside the callee is reported at the line of the call, and says which call it comes from: ``Possible integer overflow: `$x * 2` can leave the PHP int range, in the call to PhpstanContracts\Tests\Fixtures\CallFixture::double().`` When the callee calls another method, the message names the whole chain, from the call on the reported line to the method that holds the check: ``..., in the call to PhpstanContracts\Tests\Fixtures\CallFixture::quadruple(), through PhpstanContracts\Tests\Fixtures\CallFixture::double().``

What can be called:

| Call | Condition |
|---|---|
| `self::helper($x)`, `ClassName::helper($x)` | A static method. The call is bound at compile time. |
| `$this->level()`, `$other->level()`, `$cart->coupon->total()` | The object is an enum case or a data object. PHP picks the method from the runtime class, so the class must be `final` (an enum always is), or the method `final` or `private`. A `private` method is the one of the class whose code makes the call: from a class that declares a private `m()`, `$object->m()` runs that `m()` even when the object is a subclass with a public `m()` of its own, and so does the proof. |
| `$this->helper($x)`, `$this->helper($x)->other()` in a class without any instance property | `$this` has no state to model, so it is the receiver of the call and nothing else; the callee sees the same `$this`, so it can call `$this->other()` in turn, also when it is declared in a parent class of the same file. The dispatch rule is the one above: the class must be `final`, or the method `final` or `private`. A class with any instance property, of any visibility, inherited or from a trait included, is rejected, see below. |
| `static::helper($x)` | `static::` looks the method up in the called class, where a subclass's own `helper()` wins even over a private one. So the class must be `final`, or the method `final` and not `private`. |
| arguments | Positional or named. A missing argument takes its default value. An `int` is converted for a parameter whose native type is `float` or `?float`, as PHP does. PHP converts by the native type only, so a parameter without a native type, or whose native type does not match its PHPDoc type, is rejected. Any other mismatch of types is rejected. |

The callee must itself be in the verified subset; when it is not, the reason is given: ``The call to PhpstanContracts\Tests\Fixtures\CallFixture::loops() cannot be verified: Only foreach over a list is supported: ...``. Recursion, methods from a trait, methods marked `#[\Deprecated]` (calling one triggers `E_USER_DEPRECATED`, which runs the error handler), generators (``PhpstanContracts\Tests\Fixtures\Generators::five() contains a yield, so calling it returns a Generator.``) and calls inside a contract are rejected.

A called method cannot throw yet: ``The call to PhpstanContracts\Tests\Fixtures\ThrowsFixture::guarded() cannot be verified: throw is not supported in a called method yet: ...``.

**Why the same file.** PHPStan's result cache re-analyses a file when it changes, and the files that depend on it only when a signature they use changes. A proof that read the body of a method in another file would not be redone when that body changes, and could stay green for code that is now wrong. The same holds for `new X(...)`, whose proof relies on the body of the constructor being empty: X's constructor must be declared in the same file. Calls to methods of other files need modular verification, where the caller relies on the callee's contract, which is on the [roadmap](limits-and-roadmap.md#roadmap).

## Loops

A `foreach` over a `list<T>` is supported, by value, with or without the key. A rule such as "the parts of a split add up to the total" or "the total of the lines is never negative" can then be proven over the lines of an order:

```php
/**
 * @param list<OrderLine> $lines
 */
#[Requires('count($lines) <= 20')]
#[Ensures('$result >= 0')]
#[Ensures('implies(count($lines) === 0, $result === 0)')]
public static function total(array $lines): int
{
    $total = 0;
    foreach ($lines as $line) {
        $total += $line->quantity * $line->unitCents;
    }

    return $total;
}
```

This is proven for every order of up to 20 lines, quantities in `1..1000` and unit prices in `0..1000000`, and it includes the check that `$total += ...` never overflows, in every iteration. The whole file is [`examples/RealWorld/OrderTotals.php`](../examples/RealWorld/OrderTotals.php).

What a loop body can hold:

| In the body | Notes |
|---|---|
| assignments and compound assignments to local variables | An accumulator keeps its type: `$total = 0; ... $total += 0.5;` is rejected with `Variable $total changes type from int to float.` |
| `if`, `elseif`, `else`, `match` | As anywhere else. |
| `break`, `continue`, `break 2`, `continue 2` | The level must be an integer literal that does not exceed the number of loops around the statement. `continue 2` leaves the inner loops and ends the iteration of the outer one. |
| `return` | The first element that matches, for example. The report says which `return` was taken, as usual. |
| `throw` and `#[Throws]` | With the same "if and only if" meaning as outside a loop, see [Exceptions](#exceptions): the condition of a `throw` in the third iteration includes the two iterations before it. |
| `$out[] = $x`, `$out = [...$out, $x]` | On a list built in the method, which is how a result list is built: `$parts = []; foreach (...) { $parts[] = ...; }`. |
| another `foreach`, within the budget | See [The budget](#the-budget). |
| a call to a method of the same file | Its own loops are unrolled in place, with the bound of its own `#[Requires]`. |

After the loop, the key and the value hold the last values they had, as in PHP. A variable that is first assigned in the body is not defined after the loop, because the list can be empty: reading it is rejected with `Variable $seen is not defined at this point.`

PHP iterates the array as it was when the loop started. The extension does the same: appending to a list that the loop iterates, which is allowed on a list built in the method, does not add iterations, and `$work = []` in the body does not stop the loop. Appending to a parameter is rejected as everywhere (`Appending to $amounts, a list received from outside the method, is not supported: ...`).

### How a loop is proven

The body is translated once per iteration, up to a bound `N`, and each iteration only runs if the list has an element for it. That is exact for every list of at most `N` elements. For a longer list, the unrolled code does not describe what PHP runs, so the extension adds one more obligation, `contracts.loopBound`: the list never has more than `N` elements. **The bound is never assumed.** A proof is only reported as complete when the obligation holds too, and when it does not, the method fails with the list that breaks it.

A `foreach` always ends, since a list is finite, so no termination argument is needed.

### The bound

`N` is chosen, in this order, from:

1. a conjunct of a `#[Requires]` of the form `count($lines) <= 20`, `count($lines) < 21`, `20 >= count($lines)`, `21 > count($lines)` or `count($lines) === 20`, joined by `&&` or `and` at the top level of the contract, with an integer literal or an integer class constant for the number. The loop over `$lines` uses the smallest bound given to `$lines`;
2. otherwise the largest bound given to any list of the method, which covers a loop over a list built from a bounded one;
3. otherwise the number of elements of a list literal;
4. otherwise `contracts.loopUnroll`, `10` by default.

With a bound from a `#[Requires]`, the obligation is trivial: the precondition says the same thing. Without one, the proof stops at ten elements and says so. Here is `OrderTotals::totalBroken()`, which has the same body as `total()` and no `#[Requires]`:

```php
#[Ensures('$result >= 0')]
public static function totalBroken(array $lines): int
```

```
OrderTotals.php:42: The proof unrolls this foreach up to 10 iterations, and $lines can have more. This is a limit of the proof, not a bug in the code.
If the real maximum is known, add #[Requires('count($lines) <= 50')]; otherwise raise contracts.loopUnroll.
Counterexample: $lines = a list of 11 elements starting with [OrderLine(quantity: 1000, unitCents: 999997), OrderLine(quantity: 1000, unitCents: 999996), ...].
```

This diagnostic is a limit of the proof and not a bug in the code: PHP runs the method fine on that list, and the extension only tells you that the proof does not cover it. There are two ways out:

- **State the maximum** with `#[Requires('count($lines) <= 50')]`. A precondition is an assumption of the proof, so the callers have to respect it, as for any `#[Requires]`. This is the answer when the real maximum is known, such as the number of lines an order can have.
- **Raise `contracts.loopUnroll`** when the lists are short but the bound is not written down. It costs time, see [The cost](#the-cost).

When the loop is over a list that is not a parameter, a contract cannot name it, and the message suggests to bound the list it is built from with `#[Requires]`.

Because the bound is checked and not assumed, a wrong bound can never turn into a wrong proof: the worst a missing `#[Requires]` does is a `contracts.loopBound` report.

### The budget

A loop nest is unrolled as many times as the product of the bounds along the nesting: two loops over 20 lines each are 400 iterations of the inner body. When a nest would be unrolled more than `contracts.maxLoopUnroll` times (100 by default), the method is rejected before anything is sent to Z3:

```
These nested loops would be unrolled 20 × 20 = 400 times, more than contracts.maxLoopUnroll (100). Bound the lists more tightly with #[Requires], extract the inner loop into a smaller method of this file, or raise contracts.maxLoopUnroll.
```

Extracting the inner loop into a method of the same file, with its own `#[Requires]`, keeps each unrolling small. Both parameters are described in [Getting started](getting-started.md#configuration).

### The cost

Unrolling multiplies the size of the problem by `N`, so a loop whose body is linear is nearly free and a loop whose body multiplies two variables is not. Measured with Z3 5.4.1 on the machine used for this documentation, for the whole method (every check of it):

| Method | 10 elements | 20 | 50 | 100 |
|---|---|---|---|---|
| a sum of `list<int<0, 1000000>>` | 62 ms | 102 ms | 139 ms | 330 ms |
| a search with `break` and `continue` | 61 ms | 115 ms | 148 ms | 365 ms |
| a filter that builds a list | 123 ms | 394 ms | 2.7 s | 8.1 s |
| the total of an order, `$line->quantity * $line->unitCents` per line | 112 ms | 3.7 s | 34 s | 90 s |
| a discount split evenly, `array_sum($result) === $discountCents` | 405 ms | 821 ms | 3.9 s | 30 s, one check undecided |
| a discount split by weight, with a division by the sum of the weights | 30 s | 95 s, three checks undecided | | |

A loop over a hundred elements is fine when the body is linear. The cost is driven by non-linear arithmetic in the body times `N`: a product per line is comfortable up to 20 lines. The proportional split, where each line takes `intdiv($totalDiscount * $lineTotal, $subtotal)`, depends on where the remainder goes. When the last line takes it (`$discountCents - $allocated`), the sum is exact by construction, and Z3 proves it up to 5 lines (4.1 s at 5 lines). When the remainder is given back line by line in a second pass, as in the next section, the sum is proven up to 5 lines too, and answers `contracts.unknown` above that. Two nested loops of 5 elements take half a second, of 10 elements 26 seconds. A check that Z3 cannot settle within `timeoutMs` is reported as `contracts.unknown`, never as a pass.
### A discount split in proportion to the amounts
Each line takes the rounded-down share of its own amount, which leaves a few cents over, and a second pass gives them back one at a time to the lines that can still take one. The parts must add up to the discount, and the first part must stay within its own line. This is [`examples/RealWorld/ProportionalSplit.php`](../examples/RealWorld/ProportionalSplit.php):
```php
#[Requires('count($lineTotals) <= 3')]
#[Requires('$totalDiscount <= array_sum($lineTotals)')]
#[Requires('array_sum($lineTotals) >= 1')]
#[Ensures('count($result) === count($lineTotals)')]
#[Ensures('array_sum($result) === $totalDiscount')]
#[Ensures('$result[0] <= $lineTotals[0]')]
#[Ensures('$result[0] >= 0')]
public static function proportional(int $totalDiscount, array $lineTotals): array
{
    $subtotal = array_sum($lineTotals);
    $parts = [];
    $distributed = 0;
    foreach ($lineTotals as $lineTotal) {
        $part = intdiv($totalDiscount * $lineTotal, $subtotal);
        $parts[] = $part;
        $distributed += $part;
    }
    $residual = $totalDiscount - $distributed;
    $out = [];
    foreach ($parts as $i => $part) {
        if ($residual > 0 && $part < $lineTotals[$i]) {
            $part += 1;
            $residual -= 1;
        }
        $out[] = $part;
    }
    return $out;
}
```
`proportionalBroken()` leaves the second pass out, so the cents left by the rounding are lost:
```
ProportionalSplit.php:61: Postcondition #[Ensures('array_sum($result) === $totalDiscount')] can be violated.
Counterexample: $totalDiscount = 2, $lineTotals = [2, 3] => $result = [0, 1] (return at line 69).
```
The sum is the hard check. It needs the fact that the cents left over are fewer than the lines, so that one pass is enough, and that is a counting argument over every line at once, over products and divisions that Z3 cannot simplify. The time Z3 needs for it grows exponentially with the number of lines. When Z3 leaves a check `unknown`, the proof is asked again, first on its own and then cut by the length of the list, one question per length (see [How it works](how-it-works.md#loops)). That is what decides the sum from 4 to 5 lines, where the main script alone gave up. Measured on the reference machine (24 cores, Windows, Z3 5.4.1, PHP 8.5) with `contracts.timeoutMs` set to 10000, as the duration of one `phpstan analyse` of a file that holds both methods, startup included, and with other programs running on the machine. Before is the repository at commit 661c243, after is this version:
| Lines | Before: `proportional()` | Before: `proportionalBroken()` | Before: run | After: `proportional()` | After: `proportionalBroken()` | After: run |
|---|---|---|---|---|---|---|
| 3 | proven | refuted | 6.4 s | proven | refuted | 6.4 s |
| 4 | sum `unknown` | `unknown` | 38.5 s | proven | refuted | 38.9 s |
| 5 | sum `unknown` | refuted | 43.8 s | proven | refuted | 44.6 s |
| 6 | not measured | not measured | not measured | sum `unknown` | refuted | 60.1 s |
| 8 | sum and an overflow check `unknown` | `unknown` | 95.2 s | sum `unknown` | refuted | 118.7 s |
The bound of the example is 3, not 5, only to keep the run of the bundled examples short: at 4 lines it took 3 min 4 s instead of 1 min 27 s. At 3 lines the sum was already proven, so nothing changes there. At 8 lines the sum stays out of reach. The message names what to change, here for the example with its bound raised to 8, and the same 10000 ms:
```
ProportionalSplit.php:25: Not proven: Z3 answered "unknown" after 10000 ms for #[Ensures('array_sum($result) === $totalDiscount')]. The proof unrolls the foreach at line 33 up to 8 iterations and the foreach at line 40 up to 8 iterations, and the code has a product of two variables (`$totalDiscount * $lineTotal`) and a division by a variable (`intdiv($totalDiscount * $lineTotal, $subtotal)`). The split of the proof by the length of $lineTotals did not decide the lengths 5, 6, 7, and 8. Try a smaller bound with #[Requires('count($lineTotals) <= 3')], a longer contracts.timeoutMs, or a #[Requires] that narrows the ranges.
```
The refuted method is still refuted at 8 lines, because one counterexample is enough and the cases that find it end the run.

### Reading a diagnostic from a loop

Every iteration has its own copy of each safety check of the body, but the copies are reported once, at the line of the code, with the first iteration that fails:

```
ForeachFixture.php:394: Possible integer overflow: `$total + $amount` can leave the PHP int range, in iteration 2 (key 1) of the foreach at line 393.
Counterexample: $amounts = [9223372036854769954, 5854, -9223372036854775808, 0, 0, 0, 0, 0, 21656].
```

For nested loops, the message names the iteration of each: `in iteration 2 (key 1) of the foreach at line 341, in iteration 1 (key 0) of the foreach at line 340`. Lists are printed as PHP literals, a list of more than ten elements is shortened when a shorter one is also a counterexample, and every counterexample of a loop is replayed in PHP by the test suite, except `contracts.loopBound`, whose input the PHP method handles correctly (see [Testing](testing.md#counterexamples-are-replayed)).

Here the discount split of [`examples/RealWorld/DiscountSplit.php`](../examples/RealWorld/DiscountSplit.php) is proven: the cents that do not divide evenly go to the first lines, and the parts add up to the discount, to the cent, for any discount from 0 to 10,000,000 cents and any list of 1 to 20 lines:

```php
#[Requires('count($lineCents) <= 20')]
#[Ensures('count($result) === count($lineCents)')]
#[Ensures('array_sum($result) === $discountCents')]
public static function evenly(int $discountCents, array $lineCents): array
{
    $share = intdiv($discountCents, count($lineCents));
    $remainder = $discountCents - $share * count($lineCents);
    $parts = [];
    foreach ($lineCents as $i => $cents) {
        $parts[] = $i < $remainder ? $share + 1 : $share;
    }

    return $parts;
}
```

Forgetting the remainder is refuted, with the smallest case that shows it:

```
DiscountSplit.php:48: Postcondition #[Ensures('array_sum($result) === $discountCents')] can be violated.
Counterexample: $discountCents = 4, $lineCents = [5, 4, 8] => $result = [1, 1, 1] (return at line 57).
```

The solver may print another valid counterexample, with other values, on another platform or Z3 version: the contract and the line stay the same, and the replay in PHP checks whichever one it prints.

A loop that stops early works the same way. This one returns the position of the first line above a limit, skipping the lines that are free:

```php
#[Requires('count($lines) <= 20')]
#[Ensures('$result >= -1')]
#[Ensures('$result < count($lines)')]
#[Ensures('implies(count($lines) === 0, $result === -1)')]
public static function firstLineAbove(array $lines, int $limitCents): int
{
    foreach ($lines as $i => $line) {
        if ($line->unitCents === 0) {
            continue;
        }
        if ($line->quantity * $line->unitCents > $limitCents) {
            return $i;
        }
    }

    return -1;
}
```

### `array_sum()`

`array_sum($list)` is supported on a `list<int>` (a range of ints and `non-empty-list` included), in the body and in contracts. Any other list is rejected, with the reason: a list of floats returns the int `0` when it is empty and a float otherwise, and PHP converts `null`, booleans and numeric strings to numbers.

In the body, PHP adds the elements from left to right and switches to a float as soon as a partial sum leaves the `int` range, even when a later element brings it back. The extension checks every partial sum:

```
ArraySumFixture.php:53: Possible integer overflow: `array_sum($amounts)` can leave the PHP int range. Counterexample: $amounts = [1, 9223372036854775807].
```

In a contract, `array_sum()` is the mathematical sum, as `+` is. For an input list whose elements have both a lower and an upper bound, the proof also knows that the sum of the first `n` elements is between `n` times the lower bound and `n` times the upper one, which Z3 cannot derive on its own for a list of any length.

### Why not `while`, `for` and `do`

A `foreach` over a list ends, since the list is finite. A `while`, a `for` or a `do` needs an argument that it ends, a quantity that decreases at every iteration, and the extension has no way to state or check one yet. They are rejected with a message that points to `foreach ($list as $i => $item)` and to `count($list)` with `$list[$i]`. Loops without a bound, with an invariant written by the author, are on the [roadmap](limits-and-roadmap.md#roadmap).
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
| `throw E::factory($a, $b);` | A statement, with the rules below. |
| `$value ?? throw new E()`, `$ok ? $x : throw new E()`, `default => throw new E()` | A whole branch of `??`, `?:` or `match`, whose value is returned or assigned to a variable. The path that throws stops there, so nothing is computed with the missing value. |

**A static factory can build the exception.** Domain exceptions are often built by named factories, so `throw E::factory(...)` is accepted too, and `self::factory(...)` and `static::factory(...)` inside the exception class itself. `examples/RealWorld/CustomerCode.php` guards the same reserved range as `ErpCode`:

```php
#[Requires('$webId > -1000000000 && $webId < 1000000000')]
#[Throws(CodeRangeException::class, when: '$webId < 0 || $webId >= self::RANGE_END - self::OFFSET')]
#[Ensures('$result >= self::OFFSET && $result < self::RANGE_END')]
public static function from(int $webId): int
{
    $code = self::OFFSET + $webId;
    if ($code < self::OFFSET || $code >= self::RANGE_END) {
        throw CodeRangeException::outOfReservedRange($webId, $code, self::OFFSET, self::RANGE_END);
    }

    return $code;
}
```

`from()` is proven. `fromBroken()` lets the first code after the range through:

```
CustomerCode.php:42: #[Throws(CodeRangeException::class, when: '$webId < 0 || $webId >= self::RANGE_END - self::OFFSET')] is violated:
the method can return although the condition holds. Counterexample: $webId = 1000000 => $result = 99000000 (return at line 50).
```

The rules for a factory:

- It is a `static` method that the throwing method can access, and its **native** return type is one exception class: the class itself, `self` or `static`. `static` stands for the class that the call names. A PHPDoc `@return` is not enough, since PHP does not enforce it. An interface, a union, a nullable type, `never`, `stdClass` or no return type is rejected.
- The class that is matched against `#[Throws]` is that return type. A `#[Throws]` on the class or on one of its parents allows the throw. A `#[Throws]` on a subclass only does not, since the object may be of the declared class and not of the subclass.
- If the return class is not `final`, the object may also be an instance of a subclass, so a `#[Throws]` with a `when` condition on a subclass of it (or on an interface) is rejected: the proof cannot tell which condition the object falls under. Make the return class `final`, or declare a final subclass as the return type.
- The arguments are translated like any expression, so an overflow or a division by zero in an argument is reported, and they are checked against the parameters like the arguments of a [call to a method of the same file](#calls-to-other-methods): an `int` converts to a parameter whose native type is `float`, and nothing else is converted. A parameter without a default value needs an argument. Named arguments work, and a variadic or by-reference parameter is rejected.
- A factory that carries `#[Requires]`, `#[Throws]` or `#[\Deprecated]` is rejected: its precondition would not be checked, and the proof assumes that it returns normally.
- **The body of the factory is never read**, and it may be in another file. The proof assumes that the factory returns an instance of its declared class without throwing anything itself, see [What it does not guarantee](limits-and-roadmap.md#what-it-does-not-guarantee).
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
| Loop bound | a `foreach` over a list that can have more elements than the proof unrolls: a limit of the proof, not a bug in the code | `contracts.loopBound` |
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
| `contracts.loopBound` | A `foreach` over a list that can have more elements than the proof unrolls. This is a limit of the proof, not a bug in the code. | Bound the list with `#[Requires('count($list) <= 50')]`, or raise `contracts.loopUnroll`. |
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
SolverLimits.php:22: Not proven: Z3 answered "unknown" after 30000 ms for #[Ensures('$result % $perPage === 0')]. The code has a product of two variables (`($page - 1) * $perPage`) and a modulo by a variable (`$result % $perPage`), and Z3 decides non-linear arithmetic only in part. Try a longer contracts.timeoutMs or a #[Requires] that narrows the ranges.
```
The message names what made the proof expensive: the loops it unrolls with their bound, and the first product of two variables, division or modulo, and it suggests a way out.

When that happens:

1. **Bound every variable that is multiplied.** Ranges are what make products tractable.
2. **State the property as an identity.** `$result % $perPage === 0` makes Z3 reason about a remainder by a variable. Proving `$result === $perPage * ($page - 1)` says the same thing and is proved at once (see `Pagination::offset`).
3. **Split a conjunction** into several `#[Ensures]`. Each is a separate query.
4. **Lower `timeoutMs`** while iterating so that a hopeless query fails fast.
5. **Lower the bound of a loop** with `#[Requires('count($lines) <= 3')]`. Every iteration adds its own products to the proof, and a rule over 3 lines is much cheaper than one over 8. See [the proportional split](#a-discount-split-in-proportion-to-the-amounts), where 3 lines take 6 s and 8 lines are out of reach.
A check that stays `unknown` is not given up on at once. It is asked again alone, then cut by the length of the list that the loop unrolls, with several Z3 processes at the same time: [`contracts.parallelism`](getting-started.md#configuration) sets how many, and PHPStan's own workers multiply it. A method with several checks that stay `unknown` pays for that every time, so a short `timeoutMs` makes a hopeless check cheaper.

Floating point has its own limits. The same answer appears when two float variables are multiplied, and the cost of every float operation is much higher than an integer one, division more than multiplication. See [What is slow](types.md#what-is-slow).
