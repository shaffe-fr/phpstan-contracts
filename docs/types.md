# Supported types

A verified method can take and return the types below. Anything else is rejected with `contracts.unsupported` and a message that names the type.

| Type | Forms accepted | How Z3 sees it |
|---|---|---|
| [`int`](#int) | `int`, `int<min, max>`, constants | unbounded integers, bounded to 64 bits by the safety checks |
| [`bool`](#bool) | `bool`, `true`, `false` | booleans |
| [`float`](#float) | `float` | IEEE 754 binary64, round to nearest even |
| [`string`](#string) | `string`, `non-empty-string` | strings of bytes |
| [enums](#enums) | pure and backed | one constructor per case |
| [nullable](#nullable-values) | `?T`, `T\|null` for any type in this table | a value that is either none or some |
| [data classes](#readonly-data-classes) | concrete classes with public readonly properties, including DTOs of `spatie/laravel-data` | a record of the public readonly properties |

Not supported: `array` and `list<T>`, `mixed`, `object`, `callable`, closures, unions of different types (`int|string`, `int|float`), `iterable`, `void`, `never`, interfaces and abstract classes, and classes without any `public readonly` property. A class can have mutable properties as long as the proof does not read them.

A class constant whose value is a single `int`, `float`, `string`, `bool` or enum case, such as `self::OFFSET` or `ErpCode::RANGE_END`, is inlined as that value, in the body and in contracts. `static::` needs a final class or a final constant. See [Class constants, `self` and `$this`](writing-contracts.md#class-constants-self-and-this).

The rest of this page describes each type: what it can do, what it cannot, and the real bugs that the prover found in the [examples](real-world-examples.md).

## int

`int` is the full 64-bit PHP range unless a PHPDoc range narrows it (`@param int<1, 365> $nights`, `@var int<0, 100> $percent` on a property). The range is an assumption for inputs and, on a returned value, something the method must honour (see [`contracts.returnType`](#data-classes-and-the-return-type)).

In the body, every operation that can leave the 64-bit range is checked. In a contract, integers are mathematical. See [Writing contracts](writing-contracts.md#the-contract-language).

## bool

`bool` needs no explanation, with one rule: there is no implicit truthiness. `if ($count)` on an `int` is rejected, write `if ($count > 0)`.

## float

A `float` is modelled exactly as an IEEE 754 binary64 number with round-to-nearest-even, which is what PHP computes on every common 64-bit platform. The prover therefore reasons about the rounding, the infinities, `NAN` and the two zeros, not about real numbers.

| Supported | Notes |
|---|---|
| literals, `NAN`, `INF`, `PHP_FLOAT_EPSILON`, `PHP_FLOAT_MAX`, `PHP_FLOAT_MIN` | |
| `+ - *`, unary `-` | |
| `/` | PHP throws on a zero divisor, so each division carries a `contracts.divisionByZero` check. `0.0` and `-0.0` both count. |
| `< <= > >= == === != !==` | IEEE comparisons: `NAN === NAN` is false, `0.0 === -0.0` is true. |
| `abs()`, `floor()`, `ceil()`, `sqrt()` | |
| `is_nan()`, `is_infinite()`, `is_finite()` | The way to exclude `NAN` and infinities in a contract. |
| `(float) $int`, `(int) $float` | `(int)` truncates toward zero and carries a `contracts.invalidCast` check: `NAN`, infinities and values outside the `int` range are undefined. |

An `int` mixed with a `float` is converted to a `float` first, as PHP does. `$int === $float` is rejected, because it is always false in PHP.

Not supported: `round()` (its results changed between PHP versions), `min()` and `max()` on floats (the ordering of `NAN` is not modelled), `**`, `%`, `fmod()`, `fdiv()`, trigonometric, exponential and logarithmic functions.

**A `float` parameter includes `NAN` and the infinities** unless the contract excludes them. That is usually the first thing the prover finds:

```php
/**
 * abs() of NAN is NAN, and NAN is not >= 0.0.
 */
#[Ensures('$result >= 0.0')]
public static function magnitude(float $x): float
{
    return abs($x);
}
```

```
Floats.php:76: Postcondition #[Ensures('$result >= 0.0')] can be violated.
Counterexample: $x = NAN => $result = NAN (return at line 79).
```

Excluding it is a precondition:

```php
/**
 * With NaN excluded, the same contract holds.
 */
#[Requires('!is_nan($x)')]
#[Ensures('$result >= 0.0')]
public static function magnitudeOfNumber(float $x): float
{
    return abs($x);
}
```

**Contracts on floats are IEEE too.** Unlike `int`, a contract cannot use the mathematical reals: `$result === $a + ($b + $c)` is computed in floating point. That is what makes the next example possible.

### Real floating-point bugs

The `0.1 + 0.2` kind of surprise is only the start. Addition is not associative, so regrouping a sum changes it:

```php
/**
 * Regrouping a sum: float addition is not associative.
 */
#[Requires('is_finite($a) && is_finite($b) && is_finite($c)')]
#[Ensures('$result === $a + ($b + $c)')]
public static function sumRegrouped(float $a, float $b, float $c): float
{
    return ($a + $b) + $c;
}
```

```
Floats.php:67: Postcondition #[Ensures('$result === $a + ($b + $c)')] can be violated.
Counterexample: $a = 2.8823037615174624E+17, $b = -88040.40594005468, $c = 88064.40618419624
=> $result = 2.882303761517463E+17 (return at line 70).
```

Converting a price to cents by multiplying and truncating loses a cent for some prices, and 19.99 is one of them:

```php
/**
 * Converts a price to cents the way it is often written. Not every price survives it.
 */
#[Ensures('$result === 1999')]
public static function listPriceCents(): int
{
    return (int) floor(19.99 * 100.0);
}
```

```
Floats.php:46: Postcondition #[Ensures('$result === 1999')] can be violated.
Counterexample: no parameters => $result = 1998 (return at line 49).
```

The midpoint of two prices overflows to `INF` for huge bounds. The first version is the usual one. The second is the textbook fix for the integer version of this bug, and it does not work for floats, because `$high - $low` itself overflows when the bounds have opposite signs:

```php
#[Requires('is_finite($low) && is_finite($high) && $low <= $high')]
#[Ensures('is_finite($result)')]
public static function midpointBroken(float $low, float $high): float
{
    return ($low + $high) * 0.5;
}
```

```
Floats.php:16: Postcondition #[Ensures('is_finite($result)')] can be violated.
Counterexample: $low = 8.988465674574144E+307, $high = 1.7836486572363865E+308
=> $result = INF (return at line 19).
```

```php
#[Requires('is_finite($low) && is_finite($high) && $low <= $high')]
#[Ensures('is_finite($result)')]
public static function midpointSubtractBroken(float $low, float $high): float
{
    return $low + ($high - $low) * 0.5;
}
```

```
Floats.php:27: Postcondition #[Ensures('is_finite($result)')] can be violated.
Counterexample: $low = -1.7967617795809496E+308, $high = 9.3135528136621E+304
=> $result = INF (return at line 30).
```

Halving each bound first stays in range and is proven:

```php
#[Requires('is_finite($low) && is_finite($high) && $low <= $high')]
#[Ensures('is_finite($result)')]
public static function midpoint(float $low, float $high): float
{
    return $low * 0.5 + $high * 0.5;
}
```

A property that does hold, and is proven: adding 20% VAT to a non-negative net price never lowers it, even though the multiplication rounds.

```php
#[Requires('is_finite($net) && $net >= 0.0')]
#[Ensures('$result >= $net')]
#[Ensures('!is_nan($result)')]
public static function withVat(float $net): float
{
    return $net * 1.2;
}
```

### What is slow

The floating-point theory is exact and expensive. On the reference machine, the float methods of the examples take between 0.2 and 4.7 seconds each, against about 0.2 seconds for an integer, string or enum method. The time comes from the operations that the solver has to reason about bit by bit:

- **Multiplying two float variables** is the worst case: Z3 reasons about a 53-bit by 53-bit multiplier. `SolverLimits::scaled` states that `$price * $ratio <= $price` for a `$ratio` in `[0, 1]`, which is true, and Z3 does not decide it within 30 seconds, nor within a minute.
- **Division** costs more than multiplication. Halving with `* 0.5` gives exactly the same values as `/ 2.0` and is decided much faster:

| Method body | With `/ 2.0` | With `* 0.5` |
|---|---|---|
| `($low + $high)` halved | 2.1 s | 0.6 s |
| `$low + ($high - $low)` halved | 9.6 s | 2.8 s |
| `$low` and `$high` halved separately | not decided in the 10 s default | 1.7 s |

The times are for the whole method, all its checks included.

The usual workarounds are to multiply by a constant instead of dividing or multiplying by a variable, to keep the number of operations in a method small, to raise `timeoutMs`, and to keep money in integer cents.

## string

A PHP string is a sequence of bytes, and the prover models it as exactly that: every character of a string input is in `0..255`. `strlen()` counts bytes, so `strlen('€')` is 3, which is what PHP returns.

| Supported | Notes |
|---|---|
| string literals | Including non-ASCII ones, which become their UTF-8 bytes. |
| `===`, `!==` | |
| `$a . $b`, `.=` | Between strings and ints. An int becomes its decimal digits, with a `-` when it is negative, which is what PHP prints. |
| `"$days jours"`, `"Order #{$id}"`, `"($point->x, $point->y)"` | Interpolation of strings and ints, with the same conversion. |
| `(string) $n` | On an int or a string. |
| `strlen()` | |
| `str_starts_with()`, `str_ends_with()`, `str_contains()` | The needle may be empty. |
| `in_array($code, ['EUR', 'USD'], true)` | Only with `strict: true`, which compares with `===`. |
| `non-empty-string` | Adds `strlen($s) > 0` as an assumption. |

Not supported: `==` and `!=` on strings (PHP compares two numeric strings as numbers, so `"1e1" == "10"`), `<` and `>`, converting a `float` or a `bool` to a string (a float is printed according to the `precision` setting), `substr()`, `strpos()`, `trim()`, case functions, `sprintf()`, `explode()`, and regular expressions.

A label built from a count is the usual place for the conversion. The version that forgets the singular is refuted:

```php
/** @param int<1, 30> $days */
#[Ensures('implies($days === 1, $result === "1 jour")')]
public static function inStockDelayBroken(int $days): string
{
    return $days . ' jours';
}
```

```
IntToStringFixture.php:25: Postcondition #[Ensures('implies($days === 1, $result === "1 jour")')] can be violated.
Counterexample: $days = 1 => $result = '1 jours' (return at line 28).
```

In a contract, the attribute argument is a single-quoted PHP string, so write string literals with double quotes: `#[Ensures('$result === "EUR"')]`.

A string in a counterexample is printed as the PHP literal you would write: `'.jpg'`, or `"\xC2\xA3"` when it contains bytes that are not printable ASCII.

### Real string bugs

**A file name check that looks at the extension anywhere.** The first version is correct. The second uses `str_contains()`, which also accepts `shell.png.php`-style names:

```php
/**
 * Accepts only PNG and JPEG file names that have a name before the extension.
 */
#[Ensures('implies($result, str_ends_with($filename, ".png") || str_ends_with($filename, ".jpg"))')]
#[Ensures('implies($result, strlen($filename) > 4)')]
#[Ensures('implies(str_ends_with($filename, ".png") && strlen($filename) > 4, $result)')]
public static function isAllowedImage(string $filename): bool
{
    return (str_ends_with($filename, '.png') || str_ends_with($filename, '.jpg')) && strlen($filename) > 4;
}
```

```php
/**
 * Checks that the name contains an image extension, which also accepts "shell.png.php".
 */
#[Ensures('implies($result, str_ends_with($filename, ".png") || str_ends_with($filename, ".jpg"))')]
public static function isAllowedImageBroken(string $filename): bool
{
    return str_contains($filename, '.png') || str_contains($filename, '.jpg');
}
```

```
Uploads.php:35: Postcondition #[Ensures('implies($result, str_ends_with($filename, ".png") || str_ends_with($filename, ".jpg"))')]
can be violated. Counterexample: $filename = '.jpgg' => $result = true (return at line 38).
```

The version that only checks the ending is wrong in a smaller way: the file name `.jpg`, a hidden file with no name, passes.

```php
/**
 * Only looks at the extension, so the name ".jpg" (a hidden file with no name) passes.
 */
#[Ensures('implies($result, strlen($filename) > 4)')]
public static function isAllowedImageNaive(string $filename): bool
{
    return str_ends_with($filename, '.png') || str_ends_with($filename, '.jpg');
}
```

```
Uploads.php:26: Postcondition #[Ensures('implies($result, strlen($filename) > 4)')] can be violated.
Counterexample: $filename = '.jpg' => $result = true (return at line 29).
```

**A storage key that can start with a separator.** `<tenant>/<name>` starts with `/` when the tenant is empty, and the contract can be proven only once the tenant is constrained:

```php
/**
 * Same, without any rule on the tenant.
 */
#[Ensures('!str_starts_with($result, "/")')]
public static function storageKeyBroken(string $tenant, string $name): string
{
    return $tenant . '/' . $name;
}
```

```
Uploads.php:80: Postcondition #[Ensures('!str_starts_with($result, "/")')] can be violated.
Counterexample: $tenant = '', $name = '' => $result = '/' (return at line 83).
```

```php
/**
 * Builds a storage key: "<tenant>/<name>", never starting with a separator.
 *
 * @param non-empty-string $tenant
 */
#[Requires('!str_starts_with($tenant, "/")')]
#[Ensures('strlen($result) === strlen($tenant) + 1 + strlen($name)')]
#[Ensures('str_starts_with($result, $tenant)')]
#[Ensures('!str_starts_with($result, "/")')]
public static function storageKey(string $tenant, string $name): string
{
    return $tenant . '/' . $name;
}
```

Validation rules are the everyday use of strings. Both of these are proven, including the converse direction that a rule accepts everything it should:

```php
/**
 * Staff coupon codes look like "STAFF-" followed by 6 characters.
 */
#[Ensures('implies($result, strlen($code) === 12)')]
#[Ensures('implies($result, str_starts_with($code, "STAFF-"))')]
#[Ensures('implies($code === "STAFF-ABC123", $result)')]
public static function isStaffCode(string $code): bool
{
    return str_starts_with($code, 'STAFF-') && strlen($code) === 12;
}
```

```php
/**
 * 3 to 20 characters, no space.
 */
#[Ensures('implies($result, strlen($username) >= 3 && strlen($username) <= 20)')]
#[Ensures('implies($result, !str_contains($username, " "))')]
#[Ensures('implies(strlen($username) === 3 && !str_contains($username, " "), $result)')]
public static function isValidUsername(string $username): bool
{
    return strlen($username) >= 3 && strlen($username) <= 20 && !str_contains($username, ' ');
}
```

**Bytes are not characters.** A currency symbol is one character and up to three bytes:

```php
/**
 * Assumes one character is one byte, which is not true of "€" and "£".
 */
#[Ensures('strlen($result) === 1')]
public static function symbolBroken(Currency $currency): string
{
    return match ($currency) {
        Currency::Eur => '€',
        Currency::Usd => '$',
        Currency::Gbp => '£',
    };
}
```

```
CurrencyParser.php:58: Postcondition #[Ensures('strlen($result) === 1')] can be violated.
Counterexample: $currency = Currency::Gbp => $result = "\xC2\xA3" (return at line 61).
```

The correct contract is stated in bytes, and it is proven:

```php
/**
 * strlen() counts bytes: "€" takes 3, "£" takes 2 and "$" takes 1.
 */
#[Ensures('strlen($result) >= 1 && strlen($result) <= 3')]
#[Ensures('implies($currency === Currency::Usd, strlen($result) === 1)')]
public static function symbol(Currency $currency): string
{
    return match ($currency) {
        Currency::Eur => '€',
        Currency::Usd => '$',
        Currency::Gbp => '£',
    };
}
```

## enums

Pure enums and backed enums, with `int` or `string` backing values.

| Supported | Notes |
|---|---|
| `Status::Paid` | |
| `===`, `!==`, `match ($status)` | A `match` without a `default` must cover every case, or it is reported as `contracts.unhandledMatch`. |
| `$status->value` | Backed enums only. The value has the type of the backing. |
| `$status->name` | A `string`. |
| `Status::from($value)` | Carries a `contracts.invalidEnumValue` check: it throws `ValueError` for a value that is not a backing value. |
| `Status::tryFrom($value)` | Returns `?Status`. The argument must be of the backing type. |
| `self::Paid`, `static::Paid`, `self::from()`, `self::tryFrom()` | Inside the enum. An enum cannot be extended, so `static::` is the enum itself. |
| a constant of the enum, `const DEFAULT = self::Pending;` | Inlined as the case or the scalar it holds. |
| `$this` in an instance method | One of the cases: `===`, `!==`, `match ($this)`, `$this->value`, `$this->name`, and in contracts. |
| `in_array($this, [self::Paid, self::Shipped])` | With or without `strict: true`: enum cases compare by identity either way. The list can be a constant, `in_array($this, self::FINAL_STATES)`. |

`$this->isFinal()` and `$other->level()` work when the method is declared in the same file: the call is translated with the body of the method, see [Calls to other methods](writing-contracts.md#calls-to-other-methods).

Not supported: `cases()`.

Parsing an untrusted value is the usual place for a bug. `from()` throws on anything unknown, and the prover shows the input that does it. `tryFrom()` with a default is proven, and the contract can state each case:

```php
/**
 * Currency::from() throws on anything that is not a backing value.
 */
#[Pure]
public static function fromQueryBroken(string $code): Currency
{
    return Currency::from($code);
}
```

```
CurrencyParser.php:29: `\PhpstanContracts\Examples\RealWorld\Currency::from($code)` can throw ValueError: the value may not be a backing value of the enum. Use tryFrom() for untrusted input. Counterexample: $code = ''.
```

```php
/**
 * Currency from an untrusted query-string value, EUR when it is unknown.
 */
#[Ensures('implies($code === "USD", $result === Currency::Usd)')]
#[Ensures('implies($code === "GBP", $result === Currency::Gbp)')]
#[Ensures('implies($code !== "EUR" && $code !== "USD" && $code !== "GBP", $result === Currency::Eur)')]
public static function fromQuery(string $code): Currency
{
    return Currency::tryFrom($code) ?? Currency::Eur;
}
```

The round trip from a case to its value and back is proven, because `from()` is only ever called with a real backing value:

```php
/**
 * Round trip: the backing value of a currency parses back to the same currency.
 */
#[Ensures('$result === $currency')]
public static function roundTrip(Currency $currency): Currency
{
    return Currency::from($currency->value);
}
```

### Enum methods and `$this`

Most enum logic is a `match ($this)`. An enum case has no mutable state, so the method is a function of `$this`, which the prover treats as an implicit parameter. The `match` is checked for exhaustiveness like any other:

```php
#[Ensures('implies($this === StockStatus::InStock, $result)')]
#[Ensures('implies($this === StockStatus::TemporarilyUnavailable, !$result)')]
public function isPurchasable(): bool
{
    return match ($this) {
        self::InStock => true,
        self::OutOfStock, self::TemporarilyUnavailable => false,
    };
}
```

A version that keeps selling during a temporary shortage is refuted, and the counterexample gives `$this` like a parameter:

```php
#[Ensures('implies($this === StockStatus::TemporarilyUnavailable, !$result)')]
public function isPurchasableBroken(): bool
{
    return match ($this) {
        self::InStock, self::TemporarilyUnavailable => true,
        self::OutOfStock => false,
    };
}
```

```
StockStatus.php:34: Postcondition #[Ensures('implies($this === StockStatus::TemporarilyUnavailable, !$result)')] can be violated.
Counterexample: $this = StockStatus::TemporarilyUnavailable => $result = true (return at line 37).
```

Static methods that return the enum's own cases are written with `self::`, and a contract can use it too:

```php
#[Ensures('implies($result === StockAvailability::Immediate, $stockQuantity > 0)')]
#[Ensures('implies($stockQuantity <= 0, $result === self::EndOfLife)')]
public static function resolve(int $stockQuantity): self
{
    if ($stockQuantity > 0) {
        return self::Immediate;
    }

    return self::EndOfLife;
}
```

`resolveBroken`, the same method with `>=` instead of `>`, is refuted:

```
StockAvailability.php:34: Postcondition #[Ensures('implies($result === StockAvailability::Immediate, $stockQuantity > 0)')] can be violated.
Counterexample: $stockQuantity = 0 => $result = StockAvailability::Immediate (return at line 38).
```

## Nullable values

`?int`, `int|null`, `?string`, `?Status`, `?Cart` and the other forms are supported for every type on this page, as parameters, return types, and fields of data classes.

| Supported | Notes |
|---|---|
| `null` | Where a nullable value is expected: a return, an argument of `new`, an assignment to a nullable variable, a `?:` or `match` branch next to a non-null value. |
| `$x === null`, `$x !== null`, `is_null($x)`, `isset($x)` | |
| `$x ?? $default`, `$x ??= $default` | |
| `$object?->field` | Null when `$object` is null. |

Not supported: `$x == null`, which is true for `0`, `""` and `false` as well, and `empty()`.

### Null checks narrow the type

PHP code checks for null and then uses the value. The prover follows the same checks, so `$x + 1` is accepted where PHPStan would accept it, and rejected where PHPStan would not:

| A check | Narrows the value in |
|---|---|
| `if ($x !== null) { ... }` | the `if` branch; the `else` branch for `=== null` |
| `if ($x === null) { return ...; }` | the rest of the method |
| `$x !== null && ...`, `$x === null \|\| ...` | the right operand |
| `$x !== null ? ... : ...` | the matching branch |
| `match (true) { $x === null => ..., default => ... }` | the following arms |
| `$x = 5;` on a nullable variable | the rest of the path |
| `implies($x !== null, ...)` in a contract | the conclusion |

Narrowing also works for fields: after `$cart->coupon !== null`, the expression `$cart->coupon->minimumCents` is accepted. A reassignment of the variable forgets what was known about it.

Without a check, the operation is rejected, and the message says what to do:

```php
/**
 * A nullable operand needs a null check first.
 *
 * @param int<0, 100>|null $percent
 */
#[Pure]
public static function unchecked(?int $percent): int
{
    return $percent + 1;
}
```

```
Nullables.php:79: Expected a number (int or float) but got ?int, which may be null here: check it first with `!== null`, `isset()` or `??`.
```

`null` on its own needs a type to give it. `$x = null;` for a variable that does not exist yet is rejected, because the verified subset has no way to know what the variable will hold later: `Cannot infer a type for $x from null; assign a value of the type it will hold, or give a nullable parameter.`

### Real nullable bugs

An optional page size that forgets to clamp the value that was given:

```php
/**
 * Treats "no limit" as the largest allowed value.
 *
 * @param int<1, 1000>|null $limit
 */
#[Ensures('$result >= 1 && $result <= 100')]
public static function pageSize(?int $limit): int
{
    if ($limit === null) {
        return 20;
    }

    return min($limit, 100);
}
```

The version above is proven. This one is not:

```php
/**
 * Same, but the clamp was forgotten on the explicit value.
 *
 * @param int<1, 1000>|null $limit
 */
#[Ensures('$result >= 1 && $result <= 100')]
public static function pageSizeBroken(?int $limit): int
{
    return $limit ?? 20;
}
```

```
Nullables.php:65: Postcondition #[Ensures('$result >= 1 && $result <= 100')] can be violated.
Counterexample: $limit = 101 => $result = 101 (return at line 68).
```

Two optional discounts, where the contract compares against the values that were given with `??`:

```php
/**
 * The best of two optional discounts, none when neither is set.
 *
 * @param int<0, 100>|null $customerPercent
 * @param int<0, 100>|null $campaignPercent
 */
#[Ensures('$result >= 0 && $result <= 100')]
#[Ensures('$result >= ($customerPercent ?? 0)')]
#[Ensures('$result >= ($campaignPercent ?? 0)')]
#[Ensures('implies($customerPercent === null && $campaignPercent === null, $result === 0)')]
public static function bestDiscount(?int $customerPercent, ?int $campaignPercent): int
{
    return max($customerPercent ?? 0, $campaignPercent ?? 0);
}
```

A nullable result, where the contract states exactly when it is null:

```php
/**
 * Days left before an optional deadline: null when there is none.
 *
 * @param int<0, 100000>|null $deadlineDay
 * @param int<0, 100000> $today
 */
#[Ensures('implies($deadlineDay === null, $result === null)')]
#[Ensures('implies($deadlineDay !== null, $result !== null && $result >= 0)')]
#[Ensures('implies($deadlineDay !== null && $deadlineDay >= $today, $result === $deadlineDay - $today)')]
public static function daysLeft(?int $deadlineDay, int $today): ?int
{
    if ($deadlineDay === null) {
        return null;
    }

    return max(0, $deadlineDay - $today);
}
```

## Readonly data classes

A class can be a parameter, a return type, or a field of another class when all of these hold:

- it is concrete (not an interface, an abstract class or an enum),
- it has at least one `public readonly` property of a supported type, which can itself be nullable or another data class (a `readonly class` qualifies), and
- it does not refer to itself.

The `public readonly` properties of a supported type are the model of an instance. A readonly property cannot change once it is set, whatever the rest of the object does, so the class may hold other properties too: the proof leaves them out, and **reading one of them is rejected** with the reason. This is what lets a DTO that extends `Spatie\LaravelData\Data` be verified: its own properties are promoted `public readonly`, and the `protected` state that the base class brings in through its traits, such as `$_additional`, is never read.

```
Property App\Pricing\PriceContextData::$_additional must be public and readonly: only immutable data classes are supported.
Property App\Pricing\PriceContextData::$lines cannot be read in a verified method: Type list<LineData> is not supported in verified methods (...).
```

A class whose properties are all left out is rejected as a type, with the reason of the first one.

The classes of the examples:

```php
final readonly class Coupon
{
    public function __construct(
        public string $code,
        /** @var int<1, 100> */
        public int $percent,
        /** @var int<0, 100000000>|null */
        public ?int $minimumCents,
    ) {
    }
}
```

```php
final readonly class Cart
{
    public function __construct(
        /** @var int<0, 100000000> */
        public int $subtotalCents,
        public ?Coupon $coupon,
        public Currency $currency,
    ) {
    }
}
```

```php
final readonly class Receipt
{
    public function __construct(
        /** @var int<0, 100000000> */
        public int $discountCents,
        /** @var int<0, 100000000> */
        public int $totalCents,
        public bool $couponApplied,
    ) {
    }
}
```

| Supported | Notes |
|---|---|
| `$cart->subtotalCents`, `$cart->coupon->percent` | Nested reads. A nullable object needs a null check or `?->`. |
| `$cart->coupon?->minimumCents` | |
| `new Receipt(0, $total, false)` | Positional or named arguments, all of them. Only for classes whose constructor does nothing but promote every modelled property. The properties left out of the model keep their default value. |
| `$this->onHand` in a method of the class, `new self(...)` | `$this` is an implicit parameter of the class, with the same domain as any other. |

A method of the class declared in the same file, such as `$this->available()` or a getter, can be called if the class is `final`, or the method `final` or `private`: see [Calls to other methods](writing-contracts.md#calls-to-other-methods).

Not supported: calling a method declared in another file, reading a property that is left out of the model, `$a === $b` and `$a == $b` between objects (identity is not modelled, compare the fields), writing to a property, and `instanceof`.

A data class can carry its own rules. `$this` is a record of its properties, read like a parameter:

```php
#[Ensures('$result >= 0 && $result <= $this->onHand')]
#[Ensures('implies($this->reserved <= $this->onHand, $result === $this->onHand - $this->reserved)')]
public function available(): int
{
    return max(0, $this->onHand - $this->reserved);
}
```

`availableBroken` returns `$this->onHand - $this->reserved` without the `max()`, and is refuted:

```
StockLine.php:35: Postcondition #[Ensures('$result >= 0')] can be violated.
Counterexample: $this = StockLine(onHand: 0, reserved: 1000000) => $result = -1000000 (return at line 38).
```

A constructor that validates its arguments cannot be modelled, so the class can be received as a parameter but not built with `new`. The prover would otherwise skip a possible exception.

```
Rejected.php:120: Cannot instantiate Percentage: its constructor contains logic, which is not verified.
Rejected.php:127: Property PhpstanContracts\Examples\Counter::$count must be public and readonly: only immutable data classes are supported.
```

### Data classes and the return type

PHPDoc ranges on properties describe the values an instance can hold. For a parameter, they are assumptions of the proof. For a returned object, they are **proof obligations**: the method must not build an object that its own return type excludes. The same holds for any PHPDoc range on a return type, such as `@return int<0, 100>`. The obligation is reported as `contracts.returnType`.

A checkout that applies an optional coupon to a cart, with the contracts that state its rules:

```php
/**
 * Applies an optional coupon to a cart. A coupon with a minimum spend only applies above it.
 *
 * PHPStan cannot see that the total stays non-negative, so it asks about the Receipt
 * constructor argument; the first postcondition below is the proof that answers it.
 */
#[Ensures('$result->totalCents >= 0')]
#[Ensures('$result->discountCents + $result->totalCents === $cart->subtotalCents')]
#[Ensures('implies($cart->coupon === null, !$result->couponApplied && $result->discountCents === 0)')]
#[Ensures('implies($result->couponApplied, $cart->coupon !== null)')]
#[Ensures('implies($result->couponApplied, $result->discountCents <= $cart->subtotalCents)')]
public static function receipt(Cart $cart): Receipt
{
    $coupon = $cart->coupon;
    if ($coupon === null) {
        return new Receipt(0, $cart->subtotalCents, false);
    }

    if ($coupon->minimumCents !== null && $cart->subtotalCents < $coupon->minimumCents) {
        return new Receipt(0, $cart->subtotalCents, false);
    }

    $discount = intdiv($cart->subtotalCents * $coupon->percent, 100);

    return new Receipt($discount, $cart->subtotalCents - $discount, true); // @phpstan-ignore argument.type
}
```

The first postcondition is the one PHPStan cannot establish on its own, hence the `@phpstan-ignore`. The proof covers it.

A version with a flat 5.00 welcome bonus added on top breaks it, for a small cart:

```php
/**
 * A flat 5.00 welcome bonus was added on top of the percentage.
 */
#[Ensures('$result->totalCents >= 0')]
public static function receiptBroken(Cart $cart): Receipt
{
    $coupon = $cart->coupon;
    if ($coupon === null) {
        return new Receipt(0, $cart->subtotalCents, false);
    }

    $discount = intdiv($cart->subtotalCents * $coupon->percent, 100) + 500;

    return new Receipt($discount, $cart->subtotalCents - $discount, true);
}
```

```
Checkout.php:41: Postcondition #[Ensures('$result->totalCents >= 0')] can be violated.
Counterexample: $cart = Cart(subtotalCents: 0, coupon: Coupon(code: '', percent: 100, minimumCents: 0), currency: Currency::Usd)
=> $result = Receipt(discountCents: 500, totalCents: -500, couponApplied: true) (return at line 51).
Checkout.php:42: The returned value can violate the declared return type `PhpstanContracts\Examples\RealWorld\Receipt`.
Counterexample: $cart = Cart(subtotalCents: 0, coupon: Coupon(code: '', percent: 100, minimumCents: 0), currency: Currency::Usd)
=> $result = Receipt(discountCents: 500, totalCents: -500, couponApplied: true) (return at line 51).
```

Objects in a counterexample are printed with their properties, nested ones included, so the failing input can be rebuilt in a test.

The explicit postcondition is reported first, because it says what the rule is. The return-type check is reported after it and is never assumed by the postconditions, so one mistake does not hide the other.

Two more rules over the same classes, with a nullable field read through `?->` and through a nested null check:

```php
/**
 * How much more the customer must spend for the coupon to apply.
 */
#[Ensures('$result >= 0')]
#[Ensures('implies($cart->coupon?->minimumCents === null, $result === 0)')]
public static function minimumShortfall(Cart $cart): int
{
    $minimum = $cart->coupon?->minimumCents;

    return $minimum === null ? 0 : max(0, $minimum - $cart->subtotalCents);
}
```

```php
/**
 * The same rule written with a nested null check instead of a local variable.
 */
#[Ensures('$result === ($cart->coupon !== null && $cart->coupon->minimumCents === null)')]
public static function alwaysApplicable(Cart $cart): bool
{
    return $cart->coupon !== null && $cart->coupon->minimumCents === null;
}
```
