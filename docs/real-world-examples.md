# Real-world examples

Twenty-six business rules of the kind found in shops, booking engines and APIs. Each one is real code in [`examples/RealWorld`](../examples/RealWorld), checked by `phpstan analyse` with this extension. Sections 1 to 8 use integers, booleans and enums. [Section 9](#9-the-same-rules-over-richer-types) covers data classes, strings, floats, nullable values, enum methods, class constants, calls, `in_array()`, guards that throw, lists, array shapes and loops over lists, which the [types page](types.md) and [Loops](writing-contracts.md#loops) show in detail. The code blocks below are copied from those files with the prose descriptions of the docblocks left out, and the outputs are the actual diagnostics, shown with `--error-format=raw` and shortened paths.

Each section follows the same arc: the rule, the contract that states it, what the prover established, and a plausible bug it caught.

**Conventions.** Money is integer cents and VAT rates are basis points (`2000` is 20%), because floating point is a poor fit for money, as [the float bugs](types.md#real-floating-point-bugs) show. Every parameter that gets multiplied has a PHPDoc range, because the prover checks every operation for overflow.

| # | Rule | Methods | Result |
|---|---|---|---|
| 1 | Pagination | 4 | 3 proven, 1 refuted |
| 2 | VAT and credit notes | 3 | 2 proven, 1 refuted |
| 3 | Payment installments | 3 | 2 proven, 1 refuted |
| 4 | Order status workflow | 3 | 2 proven, 1 refuted |
| 5 | Booking cancellation refund | 2 | 1 proven, 1 refuted |
| 6 | Shipping fee | 2 | 1 proven, 1 refuted |
| 7 | Rate limiting | 3 | 2 proven, 1 refuted (two ways) |
| 8 | Calendar | 3 | 2 proven, 1 refuted |
| 9 | Richer types: data classes, strings, floats, nullables, enum parsing, enum methods, class constants, calls, `in_array()`, guards that throw, lists, array shapes, loops over lists | 75 | 43 proven, 32 refuted |

## 1. Pagination

**The rule.** `pageCount` says how many pages a listing needs. Everything downstream depends on it: the pager, the `last` link, the clamp on `?page=N`.

```php
/**
 * @param int<0, 100000000> $totalItems
 * @param int<1, 1000> $perPage
 */
#[Ensures('$result >= 0')]
#[Ensures('$result * $perPage >= $totalItems')]
#[Ensures('$totalItems === 0 || ($result - 1) * $perPage < $totalItems')]
public static function pageCount(int $totalItems, int $perPage): int
{
    return intdiv($totalItems + $perPage - 1, $perPage);
}
```

The second contract says that every item fits. The third says that the last page is not empty. Together they pin the function down: it is the ceiling of the division, not merely something close to it.

**Proven.** For all 100 million item counts and every page size, including the overflow check on `$totalItems + $perPage - 1` and the division by `$perPage`.

**The bug.** The classic off-by-one, written by someone who remembered that pages start at one:

```php
/**
 * @param int<0, 100000000> $totalItems
 * @param int<1, 1000> $perPage
 */
#[Ensures('$totalItems === 0 || ($result - 1) * $perPage < $totalItems')]
public static function pageCountBroken(int $totalItems, int $perPage): int
{
    return intdiv($totalItems, $perPage) + 1;
}
```

```
Pagination.php:33: Postcondition #[Ensures('$totalItems === 0 || ($result - 1) * $perPage < $totalItems')]
can be violated. Counterexample: $totalItems = 100000000, $perPage = 1 => $result = 100000001 (return at line 36).
```

Whenever the item count is an exact multiple of the page size, there is one empty page too many. A test suite with `$totalItems = 25, $perPage = 10` passes this code.

**A user-supplied page number** needs clamping. Here the contract also states that a valid page is left untouched:

```php
/**
 * @param int<-1000000, 1000000> $requestedPage
 * @param int<1, 1000000> $pageCount
 */
#[Ensures('$result >= 1 && $result <= $pageCount')]
#[Ensures('implies($requestedPage >= 1 && $requestedPage <= $pageCount, $result === $requestedPage)')]
public static function clampPage(int $requestedPage, int $pageCount): int
{
    return min(max($requestedPage, 1), $pageCount);
}
```

And the offset for the SQL `LIMIT`/`OFFSET`:

```php
/**
 * @param int<1, 1000000> $page
 * @param int<1, 1000> $perPage
 */
#[Ensures('$result >= 0')]
#[Ensures('$result === $perPage * ($page - 1)')]
#[Ensures('implies($page === 1, $result === 0)')]
#[Ensures('implies($page >= 2, $result >= $perPage)')]
public static function offset(int $page, int $perPage): int
{
    return ($page - 1) * $perPage;
}
```

The divisibility of the offset by the page size is stated as an identity on purpose: the natural `%` formulation is one of the cases Z3 cannot settle, see [When the solver gives up](#when-the-solver-gives-up).

## 2. VAT and credit notes

**The rule.** VAT is rounded half up to the nearest cent. The contract says that the rounded VAT is within half a cent of the exact value, which is what rounding to the nearest cent means, written as an inequality:

```php
/**
 * @param int<0, 1000000000> $netCents
 * @param int<0, 3000> $rateBasisPoints
 */
#[Ensures('$result >= 0')]
#[Ensures('abs($result * 10000 - $netCents * $rateBasisPoints) <= 5000')]
#[Ensures('implies($rateBasisPoints === 0, $result === 0)')]
public static function vatCents(int $netCents, int $rateBasisPoints): int
{
    return intdiv($netCents * $rateBasisPoints + 5000, 10000);
}
```

`$result * 10000` and `$netCents * $rateBasisPoints` are the VAT and the exact VAT both scaled by 10 000, so the contract compares them without any division. The `abs(...) <= 5000` is "at most half a cent".

**Proven.** Up to a net amount of 10 million units and a 30% rate.

**The bug.** Months later the shop starts issuing credit notes, so the same function is widened to negative amounts:

```php
/**
 * @param int<-1000000000, 1000000000> $netCents
 * @param int<0, 3000> $rateBasisPoints
 */
#[Ensures('abs($result * 10000 - $netCents * $rateBasisPoints) <= 5000')]
public static function vatCentsNaive(int $netCents, int $rateBasisPoints): int
{
    return intdiv($netCents * $rateBasisPoints + 5000, 10000);
}
```

```
Invoice.php:36: Postcondition #[Ensures('abs($result * 10000 - $netCents * $rateBasisPoints) <= 5000')]
can be violated. Counterexample: $netCents = -2, $rateBasisPoints = 2999 => $result = 0 (return at line 39).
```

`intdiv()` truncates toward zero. Adding `5000` before dividing rounds half up for positive numbers, but for a negative numerator it rounds the other way. A credit note of `-0.02` at 29.99% would carry no VAT at all, whereas the matching invoice carries one cent. The error is about 0.6 of a cent, over the half cent the contract allows.

**The fix** is to round the magnitude and restore the sign, so that a credit note mirrors its invoice. The contract gains the sign rules:

```php
/**
 * @param int<-1000000000, 1000000000> $netCents
 * @param int<0, 3000> $rateBasisPoints
 */
#[Ensures('abs($result * 10000 - $netCents * $rateBasisPoints) <= 5000')]
#[Ensures('implies($netCents < 0, $result <= 0)')]
#[Ensures('implies($netCents >= 0, $result >= 0)')]
public static function vatCentsSigned(int $netCents, int $rateBasisPoints): int
{
    $magnitude = intdiv(abs($netCents) * $rateBasisPoints + 5000, 10000);

    return $netCents < 0 ? -$magnitude : $magnitude;
}
```

This is proven, including the overflow check on `abs()`.

## 3. Payment installments

**The rule.** A total is split into `$count` installments. The first ones absorb the remainder, so two installments of a 1.01 total are 0.51 and 0.50.

```php
/**
 * @param int<0, 100000000> $totalCents
 * @param int<1, 120> $count
 */
#[Requires('$index >= 0 && $index < $count')]
#[Ensures('$result >= intdiv($totalCents, $count)')]
#[Ensures('$result <= intdiv($totalCents, $count) + 1')]
#[Ensures('implies($index < $totalCents % $count, $result === intdiv($totalCents, $count) + 1)')]
public static function installmentCents(int $totalCents, int $count, int $index): int
{
    $base = intdiv($totalCents, $count);
    $remainder = $totalCents % $count;

    return $index < $remainder ? $base + 1 : $base;
}
```

"The installments add up to the total" can be stated without a loop, in closed form, and a loop over a list can sum them too (see [Loops](writing-contracts.md#loops)). Here it is the closed form: `$remainder` installments of `$base + 1` and the others of `$base`.

```php
/**
 * @param int<0, 100000000> $totalCents
 * @param int<1, 120> $count
 */
#[Ensures('$result === $totalCents')]
public static function sumOfInstallments(int $totalCents, int $count): int
{
    $base = intdiv($totalCents, $count);
    $remainder = $totalCents % $count;

    return $remainder * ($base + 1) + ($count - $remainder) * $base;
}
```

**Proven.** No cent is lost or created, for every total and every number of installments up to 120. The identity involves a product of two variables, and Z3 settles it.

**The bug.** The version that rounds every installment down and forgets the remainder:

```php
/**
 * @param int<0, 100000000> $totalCents
 * @param int<1, 120> $count
 */
#[Ensures('$result === $totalCents')]
public static function sumOfInstallmentsNaive(int $totalCents, int $count): int
{
    return $count * intdiv($totalCents, $count);
}
```

```
Invoice.php:97: Postcondition #[Ensures('$result === $totalCents')] can be violated.
Counterexample: $totalCents = 1, $count = 3 => $result = 0 (return at line 100).
```

A one-cent total in three installments collects nothing. The ledger drifts by up to `$count - 1` cents per order and nobody notices until the accounts do not balance.

## 4. Order status workflow

**The rule.** Which status changes are legal. This is a finite state machine over a string-backed enum, and the contracts are its invariants:

```php
#[Ensures('implies($from === OrderStatus::Cancelled || $from === OrderStatus::Refunded, !$result)')]
#[Ensures('implies($to === OrderStatus::Pending, !$result)')]
#[Ensures('implies($from === $to, !$result)')]
#[Ensures('implies($from === OrderStatus::Shipped && $result, $to === OrderStatus::Delivered)')]
public static function canTransition(OrderStatus $from, OrderStatus $to): bool
{
    return match ($from) {
        OrderStatus::Pending => $to === OrderStatus::Paid || $to === OrderStatus::Cancelled,
        OrderStatus::Paid => $to === OrderStatus::Shipped
            || $to === OrderStatus::Cancelled
            || $to === OrderStatus::Refunded,
        OrderStatus::Shipped => $to === OrderStatus::Delivered,
        OrderStatus::Delivered => $to === OrderStatus::Refunded,
        OrderStatus::Cancelled, OrderStatus::Refunded => false,
    };
}
```

The four contracts say, in order: final statuses have no way out, nothing goes back to `Pending`, no self-transition, and a shipped order can only be delivered.

**Proven.** All 36 pairs of statuses, by case analysis. The `match` is also proven exhaustive: adding a seventh case to the enum without updating it turns this method red:

```
OrderWorkflow.php:20: This match expression can throw UnhandledMatchError.
Counterexample: $from = OrderStatus::Returned, $to = OrderStatus::Pending.
```

PHPStan's own `match.unhandled` rule reports the same match at the same line, and the extension adds a concrete input to it.

The same file has a third method, `isFinal()`, whose contract says that it returns `true` for exactly the two final statuses. It is proven too.

**The bug.** A "small change" that lets customers cancel an order the carrier already holds:

```php
#[Ensures('implies($from === OrderStatus::Shipped && $result, $to === OrderStatus::Delivered)')]
public static function canTransitionBroken(OrderStatus $from, OrderStatus $to): bool
{
    return match ($from) {
        OrderStatus::Pending => $to === OrderStatus::Paid || $to === OrderStatus::Cancelled,
        OrderStatus::Paid => $to === OrderStatus::Shipped
            || $to === OrderStatus::Cancelled
            || $to === OrderStatus::Refunded,
        OrderStatus::Shipped => $to === OrderStatus::Delivered || $to === OrderStatus::Cancelled,
        OrderStatus::Delivered => $to === OrderStatus::Refunded,
        OrderStatus::Cancelled, OrderStatus::Refunded => false,
    };
}
```

```
OrderWorkflow.php:34: Postcondition #[Ensures('implies($from === OrderStatus::Shipped && $result, $to === OrderStatus::Delivered)')]
can be violated. Counterexample: $from = OrderStatus::Shipped, $to = OrderStatus::Cancelled => $result = true (return at line 37).
```

The rule lives in one place, but the contract states the business policy independently from the code. Someone changing the table has to change the policy too, and the policy is the part a reviewer reads.

## 5. Booking cancellation refund

**The rule.** A refund depends on how many days remain before arrival, on whether the guest booked a flexible rate, and on a non-refundable deposit.

```php
/**
 * @param int<0, 100000000> $paidCents
 * @param int<0, 100000000> $depositCents
 * @param int<-365, 730> $daysBeforeArrival negative once the stay has started
 */
#[Requires('$depositCents <= $paidCents')]
#[Ensures('$result >= 0')]
#[Ensures('$result <= $paidCents - $depositCents')]
#[Ensures('implies($flexibleRate && $daysBeforeArrival >= 1, $result === $paidCents - $depositCents)')]
#[Ensures('implies(!$flexibleRate && $daysBeforeArrival < 3, $result === 0)')]
public static function refundCents(
    int $paidCents,
    int $depositCents,
    int $daysBeforeArrival,
    bool $flexibleRate,
): int {
    if ($flexibleRate && $daysBeforeArrival >= 1) {
        $refundable = $paidCents;
    } elseif ($daysBeforeArrival >= 30) {
        $refundable = $paidCents;
    } elseif ($daysBeforeArrival >= 14) {
        $refundable = intdiv($paidCents * 50, 100);
    } elseif ($daysBeforeArrival >= 3) {
        $refundable = intdiv($paidCents * 20, 100);
    } else {
        $refundable = 0;
    }

    return max(0, $refundable - $depositCents);
}
```

This is where the SSA translation earns its keep: `$refundable` is assigned in five branches and read once, and the prover follows all five.

**Proven.** The refund is never negative and never more than what was paid minus the deposit. A flexible rate gets everything back until the day before, and a standard rate gets nothing in the last three days, even after the stay has started.

**The bug.** Forgetting the floor at zero:

```php
/**
 * @param int<0, 100000000> $paidCents
 * @param int<0, 100000000> $depositCents
 * @param int<-365, 730> $daysBeforeArrival
 */
#[Requires('$depositCents <= $paidCents')]
#[Ensures('$result >= 0')]
public static function refundCentsBroken(
    int $paidCents,
    int $depositCents,
    int $daysBeforeArrival,
    bool $flexibleRate,
): int {
    // ... same branches ...

    return $refundable - $depositCents;
}
```

```
CancellationPolicy.php:53: Postcondition #[Ensures('$result >= 0')] can be violated.
Counterexample: $paidCents = 5, $depositCents = 5, $daysBeforeArrival = 14, $flexibleRate = false => $result = -3 (return at line 72).
```

Cancelling two weeks out, the guest is owed 50% of what they paid, which is less than the deposit, so the "refund" is negative (-3 cents in the counterexample). A payment gateway that receives a negative refund can reject it or treat it as a charge. The deposit and the percentage tier interact, and the case only exists for some combinations of the two.

## 6. Shipping fee

**The rule.** A fee built from a zone, a weight surcharge, an express supplement, a free-shipping threshold, and a hard cap of 50.00 that must hold in all cases.

```php
/**
 * @param int<0, 100000> $weightGrams
 * @param int<0, 100000000> $orderCents
 */
#[Ensures('$result >= 0 && $result <= 5000')]
#[Ensures('implies($zone === ShippingZone::Domestic && !$express && $orderCents >= 5000, $result === 0)')]
#[Ensures('implies($express, $result >= 1480)')]
public static function shippingCents(ShippingZone $zone, int $weightGrams, int $orderCents, bool $express): int
{
    if ($zone === ShippingZone::Domestic && !$express && $orderCents >= 5000) {
        return 0;
    }

    $base = match ($zone) {
        ShippingZone::Domestic => 490,
        ShippingZone::Europe => 1290,
        ShippingZone::International => 2490,
    };

    $extraGrams = max(0, $weightGrams - 1000);
    $fee = $base + intdiv($extraGrams + 499, 500) * 150;

    if ($express) {
        $fee += 990;
    }

    return min($fee, 5000);
}
```

`ShippingZone` is a pure enum (no backing values). The third contract is a lower bound: an express parcel is never cheaper than the cheapest base fee plus the supplement, `490 + 990`.

**Proven.** The cap holds for every zone, weight and order total, and free shipping applies wherever the policy says it does.

**The bug.** Someone applies the cap before the express supplement, so the supplement lands on top of it:

```php
/**
 * @param int<0, 100000> $weightGrams
 * @param int<0, 100000000> $orderCents
 */
#[Ensures('$result <= 5000')]
public static function shippingCentsBroken(ShippingZone $zone, int $weightGrams, int $orderCents, bool $express): int
{
    $base = match ($zone) {
        ShippingZone::Domestic => 490,
        ShippingZone::Europe => 1290,
        ShippingZone::International => 2490,
    };

    $extraGrams = max(0, $weightGrams - 1000);
    $fee = min($base + intdiv($extraGrams + 499, 500) * 150, 5000);

    if ($express) {
        $fee += 990;
    }

    return $fee;
}
```

```
Shipping.php:49: Postcondition #[Ensures('$result <= 5000')] can be violated. Counterexample: $zone = ShippingZone::International,
$weightGrams = 9001, $orderCents = 0, $express = true => $result = 5990 (return at line 65).
```

Both orderings read naturally in a code review. The failure needs a heavy parcel, an expensive zone and the express option together, a combination that ordinary test data does not contain.

## 7. Rate limiting: the prover refuses to guess your input range

**The rule.** A token bucket regains tokens with elapsed time, up to its capacity.

```php
/**
 * @param int<1, 100000> $capacity
 * @param int<0, 100000> $tokens
 * @param int<0, 86400000> $elapsedMs at most one day
 * @param int<0, 10000> $refillPerSecond
 */
#[Requires('$tokens <= $capacity')]
#[Ensures('$result >= $tokens')]
#[Ensures('$result <= $capacity')]
public static function refilledTokens(int $capacity, int $tokens, int $elapsedMs, int $refillPerSecond): int
{
    return min($capacity, $tokens + intdiv($elapsedMs * $refillPerSecond, 1000));
}
```

**Proven.** A bucket never loses tokens by waiting and never exceeds its capacity. The multiplication `$elapsedMs * $refillPerSecond` peaks at `8.64e11`, far from the `int` limit, and the prover checks it.

**The bug.** The same code, with `$elapsedMs` left as a plain `int`, as it would naturally be written:

```php
/**
 * @param int<1, 100000> $capacity
 * @param int<0, 100000> $tokens
 * @param int<0, 10000> $refillPerSecond
 */
#[Requires('$tokens <= $capacity')]
#[Ensures('$result >= $tokens')]
public static function refilledTokensUnbounded(int $capacity, int $tokens, int $elapsedMs, int $refillPerSecond): int
{
    return min($capacity, $tokens + intdiv($elapsedMs * $refillPerSecond, 1000));
}
```

```
RateLimit.php:36: Postcondition #[Ensures('$result >= $tokens')] can be violated. Counterexample: $capacity = 100000,
$tokens = 100000, $elapsedMs = -1, $refillPerSecond = 10000 => $result = 99990 (return at line 39).
RateLimit.php:39: Possible integer overflow: `$elapsedMs * $refillPerSecond` can leave the PHP int range.
Counterexample: $capacity = 1, $tokens = 0, $elapsedMs = 9223372036854775807, $refillPerSecond = 10000.
```

Two defects from one missing range. A negative elapsed time, which a clock adjustment can produce, makes the bucket lose tokens. A huge one overflows the multiplication, and PHP silently turns the product into a float.

Neither shows up with ordinary test data. The prover does not assume anything you did not write down, and writing down `int<0, 86400000>` is the fix.

A helper in the same spirit, how long to wait for `$cost` tokens, is a ceiling division. It is proven the same way as the page count:

```php
/**
 * @param int<1, 100000> $cost
 * @param int<0, 100000> $tokens
 * @param int<1, 10000> $refillPerSecond
 */
#[Ensures('$result >= 0')]
#[Ensures('implies($tokens >= $cost, $result === 0)')]
#[Ensures('$result * $refillPerSecond >= $cost - $tokens')]
public static function secondsUntilAvailable(int $cost, int $tokens, int $refillPerSecond): int
{
    $missing = max(0, $cost - $tokens);

    return intdiv($missing + $refillPerSecond - 1, $refillPerSecond);
}
```

## 8. Calendar

**The rule.** The Gregorian leap-year rule is a good test of a contract: it has four cases, and the contract can state each one on its own.

```php
/**
 * @param int<1, 9999> $year
 */
#[Ensures('implies($year % 4 !== 0, !$result)')]
#[Ensures('implies($year % 4 === 0 && $year % 100 !== 0, $result)')]
#[Ensures('implies($year % 100 === 0 && $year % 400 !== 0, !$result)')]
#[Ensures('implies($year % 400 === 0, $result)')]
public static function isLeapYear(int $year): bool
{
    return ($year % 4 === 0 && $year % 100 !== 0) || $year % 400 === 0;
}
```

**The bug.** The every-fourth-year shortcut, which is wrong for century years such as 1900, 2100 and 2200:

```php
/**
 * @param int<1, 9999> $year
 */
#[Ensures('implies($year % 100 === 0 && $year % 400 !== 0, !$result)')]
public static function isLeapYearBroken(int $year): bool
{
    return $year % 4 === 0;
}
```

```
Calendar.php:30: Postcondition #[Ensures('implies($year % 100 === 0 && $year % 400 !== 0, !$result)')]
can be violated. Counterexample: $year = 100 => $result = true (return at line 33).
```

The counterexample is year 100, the smallest one, not the 1900 you would try by hand.

The same rule is used inside `daysInMonth`, which combines an `if`, a `match` with a `default` arm and a ternary, and states the month lengths as contracts:

```php
/**
 * @param int<1, 9999> $year
 * @param int<1, 12> $month
 */
#[Ensures('$result >= 28 && $result <= 31')]
#[Ensures('implies($month !== 2, $result >= 30)')]
#[Ensures('implies($month === 2 && $year % 4 !== 0, $result === 28)')]
#[Ensures('implies($month === 2 && $year % 100 === 0 && $year % 400 !== 0, $result === 28)')]
#[Ensures('implies($month === 2 && $year % 400 === 0, $result === 29)')]
public static function daysInMonth(int $year, int $month): int
{
    if ($month === 2) {
        $leap = ($year % 4 === 0 && $year % 100 !== 0) || $year % 400 === 0;

        return $leap ? 29 : 28;
    }

    return match ($month) {
        4, 6, 9, 11 => 30,
        default => 31,
    };
}
```

## 9. The same rules over richer types

Real signatures are rarely just integers. The rules below use the types that sections 1 to 8 did not: readonly data classes, strings, floats, nullable values and string-backed enums, and the way enums and value objects are usually written, with `self::`, class constants, `$this`, calls to their own methods, `in_array()`, guards that throw, lists, array shapes and loops over lists. Each row is a file of `examples/RealWorld`, and the [types page](types.md) shows the code and the diagnostics of every one.

| Rule | File | What it exercises | Methods | Result | The bug the prover found |
|---|---|---|---|---|---|
| [Checkout with an optional coupon](types.md#data-classes-and-the-return-type) | `Checkout.php` | data classes, nullable fields, `?->` | 4 | 3 proven, 1 refuted | a flat 5.00 bonus makes the total negative |
| [Parsing and printing currencies](types.md#enums) | `CurrencyParser.php` | string-backed enum, `from()`, `tryFrom()`, `->value` | 5 | 3 proven, 2 refuted | `from()` throws on unknown input, and `"€"` is 3 bytes, not 1 |
| [File uploads and storage keys](types.md#real-string-bugs) | `Uploads.php` | strings, `in_array()` with `strict: true` | 9 | 5 proven, 4 refuted | `str_contains()` accepts `shell.png.php`; a hidden file named `.jpg`; a key that starts with `/`; a list of extensions that forgets `jpeg` |
| [Optional values](types.md#real-nullable-bugs) | `Nullables.php` | nullable ints, `??`, null checks | 4 | 3 proven, 1 refuted | a clamp forgotten on the value that was given |
| [Floating point](types.md#real-floating-point-bugs) | `Floats.php` | `float` | 8 | 3 proven, 5 refuted | a midpoint that overflows, `floor(19.99 * 100)` is 1998, `NAN`, a regrouped sum |
| [Stock availability](types.md#enum-methods-and-this) | `StockAvailability.php` | an enum returning `self::` cases | 2 | 1 proven, 1 refuted | `>=` offers a product with no stock |
| [Purchasable stock statuses](types.md#enum-methods-and-this) | `StockStatus.php` | `match ($this)`, `$this->value` | 3 | 2 proven, 1 refuted | a temporary shortage still for sale |
| [Stock left to sell](types.md#readonly-data-classes) | `StockLine.php` | `$this` in a data class | 2 | 1 proven, 1 refuted | over-reservation makes the stock negative |
| [A code range reserved in the ERP](writing-contracts.md#class-constants-self-and-this) | `ErpCode.php` | class constants, `self::` | 2 | 1 proven, 1 refuted | the last id gets the first code outside the range |
| [The same range, as a guard that throws](writing-contracts.md#exceptions) | `ErpCode.php` | `#[Throws(..., when: ...)]` | 2 | 1 proven, 1 refuted | the guard lets the last id through |
| [A `match` default that cannot happen](writing-contracts.md#exceptions) | `ErpCode.php` | an undeclared `throw` in a `match` default, proven unreachable | 2 | 1 proven, 1 refuted | the domain grew and the default arm is reached at id 1000 |
| [Roles that manage each other](writing-contracts.md#calls-to-other-methods) | `UserRole.php` | an enum calling its own method, `in_array()` over cases and over a constant | 5 | 3 proven, 2 refuted | `<` stops a role from managing its own level; the constant of prescribers forgets the validator |
| [Delivery labels](types.md#string) | `DeliveryLabel.php` | an int in `.`, in an interpolation and in `(string)`; a static helper with a `#[Requires]` checked at the call | 6 | 4 proven, 2 refuted | "Ships in 1 days"; a guard of `>= 1` calls a helper that needs `>= 2` |
| [Matching a ledger line with a bank line](types.md#services-without-state) | `ReconciliationDeltaGuard.php` | a final service without properties calling its private helpers through `$this` | 4 | 3 proven, 1 refuted | the signed difference goes through the tolerance check, so a bank line far below the ledger line is matched |
| [A DTO of a data framework](types.md#readonly-data-classes) | `PriceContext.php` | a data class extending a base class with protected mutable state | 2 | 1 proven, 1 refuted | the bulk discount starts at eleven items. A third method, which reads the base state, is rejected with `contracts.unsupported` |
| [Splitting a gross price into net and VAT](types.md#real-array-shape-bugs) | `VatSplit.php` | an array shape as a result and as an input, `$result["net"]` in a contract | 3 | 2 proven, 1 refuted | two rounded-down divisions lose a cent: 2 cents split at a rate of 1 basis point add up to 1 |
| [The lines of an order](types.md#real-list-bugs) | `OrderLines.php` | `list<T>`, `count()`, reads by index, `[...$list, $x]`, `in_array()` over a list | 6 | 4 proven, 2 refuted | a guard with `<=` reads the key that does not exist; an appended amount outside the declared range |
| [Splitting a discount over the lines of an order](writing-contracts.md#loops) | `DiscountSplit.php` | `foreach` with a key, `$parts[] = ...` in the body, `array_sum($result)` in a contract, a `#[Requires]` that bounds the list and relates it to the discount | 4 | 2 proven, 2 refuted | the remainder is forgotten, so 4 cents split over three lines add up to 3; without `$discountCents <= array_sum($lineCents)`, part of the discount is lost |
| [Totals over the lines of an order](writing-contracts.md#loops) | `OrderTotals.php` | `foreach` over a list of data classes, an early `return` and `continue` in the body, a filter that builds a list, `contracts.loopBound` | 6 | 3 proven, 3 refuted | the same total without a bound on the lines is not proven beyond 10 lines, and the proof says so; a `return $i + 1` points past the last line; a filter that claims to keep every line |

Two of them are worth reading first. The checkout shows a rule over an object graph, with a nullable object inside a cart, which is the shape of most real signatures. The float page shows bugs that no unit test with ordinary values finds, such as a regrouped sum that differs in the last bit.

## Structuring real code for verification

A verified method cannot read an entity or call a repository: it has no mutable objects, no loops other than a `foreach` over a bounded list and no calls outside its own file. That is by design, and it suggests a shape for the code: **extract the rule into a small static method that takes scalars, enums, nullable values or small readonly data classes, and keep the glue in the service.**

```php
// Application code: not verified. It adapts the domain model to the pure rule.
final class CheckoutService
{
    public function shipping(Order $order): int
    {
        return Shipping::shippingCents(
            $order->zone(),
            $order->totalWeightGrams(),
            $order->subtotalCents(),
            $order->isExpress(),
        );
    }
}
```

A readonly data class can cross the boundary as it is: `Checkout::receipt()` takes a `Cart` and returns a `Receipt`. The service is ordinary code that PHPStan analyses as usual. It also meets the verified method at its boundary: `shippingCents` declares `@param int<0, 100000> $weightGrams`, so PHPStan will ask you to establish that range at this call, for example with `@return int<0, 100000>` on `totalWeightGrams()` or by clamping the value. That request is the check that the proof's assumption is true in production.

Two things are not checked yet: the extension does not verify that a call from ordinary code satisfies the callee's `#[Requires]` (only PHPDoc ranges, which PHPStan enforces), and a verified method can only call methods declared in its own file. See [Limits and roadmap](limits-and-roadmap.md).

## When the solver gives up

`offset` in section 1 states its fact as an identity. The more natural contract is divisibility:

```php
/**
 * @param int<1, 1000000> $page
 * @param int<1, 1000> $perPage
 */
#[Ensures('$result % $perPage === 0')]
public static function offsetDivisibility(int $page, int $perPage): int
{
    return ($page - 1) * $perPage;
}
```

```
SolverLimits.php:22: Not proven: Z3 answered "unknown" after 30000 ms for #[Ensures('$result % $perPage === 0')].
```

The statement is true. But it asks Z3 to reason about a remainder by a variable of a product of two variables, which is non-linear integer arithmetic, and the solver does not settle it. The extension reports it as `contracts.unknown` and never as a pass, because "no answer" is not a proof.

The identity `$result === $perPage * ($page - 1)` says the same thing, and it is proven at once. Reformulating a contract so that it avoids `%` and `intdiv` by a variable is the main technique when this happens. [Writing contracts](writing-contracts.md#when-z3-gives-up) lists the others.

The same happens in floating point, for a different reason. Multiplying two float variables makes the solver reason about a 53-bit by 53-bit multiplier. This statement is true, since a price multiplied by a ratio between 0 and 1 cannot grow, and it is not decided:

```php
/**
 * Multiplying two float variables is the expensive case of the floating-point theory:
 * the solver has to reason about a 53-bit by 53-bit multiplier. A constant factor is cheap.
 */
#[Requires('is_finite($price) && $price >= 0.0 && $price <= 1000000.0')]
#[Requires('$ratio >= 0.0 && $ratio <= 1.0')]
#[Ensures('$result <= $price')]
public static function scaled(float $price, float $ratio): float
{
    return $price * $ratio;
}
```

```
SolverLimits.php:34: Not proven: Z3 answered "unknown" after 30000 ms for #[Ensures('$result <= $price')].
```

Multiplying by a constant is cheap, so `withVat()` in [the types page](types.md#real-floating-point-bugs), which multiplies by `1.2`, is proven.
