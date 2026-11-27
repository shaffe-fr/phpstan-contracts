# phpstan-contracts

**Unit tests check a few examples. phpstan-contracts checks all of them.**

Write the rule your code must obey, right above the method. PHPStan then *proves* it for every possible input, or hands you the exact input that breaks it.

[![CI](https://github.com/shaffe-fr/phpstan-contracts/actions/workflows/ci.yml/badge.svg)](https://github.com/shaffe-fr/phpstan-contracts/actions/workflows/ci.yml)
![PHP 8.3+](https://img.shields.io/badge/PHP-8.3%2B-777bb4)
![PHPStan 2.3+](https://img.shields.io/badge/PHPStan-2.3%2B-4e8cae)
[![License: MIT](https://img.shields.io/badge/license-MIT-green)](LICENSE)

## See it in 20 seconds

You wrote a pagination helper. It looks right. Your tests pass.

```php
use PhpstanContracts\Attribute\Ensures;

/**
 * @param int<0, 100000000> $totalItems
 * @param int<1, 1000> $perPage
 */
#[Ensures('$totalItems === 0 || ($result - 1) * $perPage < $totalItems')]  // "the last page is never empty"
public static function pageCount(int $totalItems, int $perPage): int
{
    return intdiv($totalItems, $perPage) + 1;
}
```

Run `vendor/bin/phpstan analyse`:

```
Postcondition #[Ensures('$totalItems === 0 || ($result - 1) * $perPage < $totalItems')] can be violated.
Counterexample: $totalItems = 100000000, $perPage = 1 => $result = 100000001 (return at line 12).
```

Your tests used 25 items and 10 per page. The bug only shows when the item count is an exact multiple of the page size. The prover did not need to guess: it searched every case and found one.

Fix the code and the error disappears. That silence is a proof, not an absence of failing tests.

## How it works

1. **You state a rule** in plain PHP: `#[Ensures('$result >= 0')]` means "this method never returns a negative number".
2. **You run PHPStan** like you already do.
3. **A solver does the thinking.** The method and its rules are handed to [Z3](https://github.com/Z3Prover/z3), a mathematical engine used to verify hardware and critical software. It answers one of two things:
   - *"No input can break this rule."* PHPStan stays quiet.
   - *"Here is an input that breaks it."* PHPStan reports it, with the values.

No new language to learn, no test data to invent, no framework to adopt. Rules are attributes, results are PHPStan errors, and you can adopt it one method at a time.

## Concepts and prior art

- **Design by Contract** (Bertrand Meyer, Eiffel): `#[Requires]` is a *precondition*, `#[Ensures]` a *postcondition*. The method and its callers agree on a contract.
- **Static verification / formal verification**: contracts are checked at analysis time, without running the code. This is the same family as Dafny, Frama-C, SPARK Ada, Liquid Haskell and JML/OpenJML, brought to PHP as a PHPStan extension.
- **SMT solving**: the method body is translated to SMT-LIB (satisfiability modulo theories: integer arithmetic, IEEE 754 floating point, strings, arrays with a length for lists, enumerations and algebraic datatypes for data classes and array shapes) and sent to the Z3 solver. The extension asks whether the negation of the contract is satisfiable.
  - `unsat`: no input violates the contract. The contract is *proven* and nothing is reported.
  - `sat`: the model returned by Z3 is a *counterexample*, shown with the parameter values, the result and the `return` that produced it.
  - `unknown` or timeout: reported as `contracts.unknown`, never treated as a pass. The effort is bounded by a time limit per check and per method, and a check that stays undecided is remembered, so the next run does not spend the time again.
- **SSA translation and symbolic execution**: branches, `match` and local variables are put in static single assignment form, so every path is covered at once instead of being sampled.
- **Verification conditions and safety obligations**: besides your contracts, every arithmetic operation generates proof obligations: no integer overflow, no division by zero, no `UnhandledMatchError`, no read of a missing list key, no missing `return`.
- **Refinement types for parameters**: PHPDoc ranges such as `int<1, 1000>` define the input domain, which acts like a refinement type on the parameter.
- **Property-based testing, but exhaustive**: a contract is a property like in QuickCheck or Pest's datasets, except that the solver covers the whole domain instead of random samples.

The guarantee is relative to the supported subset and to the declared domain. See [Limits and roadmap](docs/limits-and-roadmap.md).

## Why it matters more with AI-written code

Coding agents and assistants write plausible code fast. The bottleneck becomes trusting it. Reviewing every diff line by line does not scale, and tests written by the same model often share its blind spots: the model that forgot the exact-multiple case in the code forgets it in the test too.

A contract changes who checks what:

- **You write the rule once, the agent cannot weaken it.** The business rule lives in an attribute that you review. Whatever the agent does to the body, the proof is redone on every run, and a regression fails the build.
- **The check is independent of the author.** The solver does not read the implementation to be convinced, it searches for a violation. It does not matter whether a human or a model wrote the code.
- **Feedback an agent can act on.** A failure is a concrete counterexample (`$totalItems = 100000000, $perPage = 1 => $result = 100000001`), which is exactly what a model needs to fix its own code in a loop. No flaky test, no vague stack trace.
- **Cheap review.** Reading three `#[Ensures]` lines is faster than reading an implementation and its test suite, and says more about what is guaranteed.
- **Deterministic and local.** No LLM is involved in the verification, and nothing leaves your machine.

Point your agent at it: ask it to write the contract first, run `vendor/bin/phpstan analyse`, and iterate until PHPStan is silent. You review the contract, the prover reviews the code.

## What it catches that tests usually miss

- Off-by-one errors on boundaries (exact multiples, first and last item).
- Negative amounts, zero quantities, and other inputs nobody thought of.
- Discounts that add up to more than 100%.
- Rounding that loses a cent: parts of a discount or of a VAT split that do not add up to the total.
- A guard that does not match what it claims: an exception thrown outside the declared range, or not thrown inside it.
- Integer overflow and division by zero, checked automatically on every verified method.
- A `match` that forgets a case, or a code path that returns nothing.
- A refactoring that quietly weakens a business rule: the proof is redone on every run.

## Try it

```bash
composer require --dev shaffe-fr/phpstan-contracts
vendor/bin/phpstan-contracts-install-z3      # downloads the official Z3 build into a per-user cache, checked against pinned SHA-256 hashes
```

If you use `phpstan/extension-installer`, you are done. Otherwise add this to `phpstan.neon`:

```neon
includes:
	- vendor/shaffe-fr/phpstan-contracts/extension.neon
```

> The package is not on Packagist yet. Until then, add this repository as a Composer VCS repository:
> `composer config repositories.phpstan-contracts vcs https://github.com/shaffe-fr/phpstan-contracts`

Then annotate a method:

```php
use PhpstanContracts\Attribute\Ensures;
use PhpstanContracts\Attribute\Requires;

final class Pricing
{
    /**
     * @param int<0, 1000000> $unitPriceCents
     * @param int<0, 100> $discountPercent
     */
    #[Requires('$nights >= 1 && $nights <= 365')]   // what callers must respect
    #[Ensures('$result >= 0')]                        // what the method promises
    #[Ensures('$result <= $unitPriceCents * $nights')]
    public static function stayTotal(int $unitPriceCents, int $nights, int $discountPercent): int
    {
        $gross = $unitPriceCents * $nights;

        if ($nights >= 7) {
            $discountPercent = min(100, $discountPercent + 10);
        }

        return intdiv($gross * (100 - $discountPercent), 100);
    }
}
```

`#[Requires]` is the precondition, `#[Ensures]` is the postcondition. In contracts, `$result` is the returned value, and `implies(a, b)` means "if a then b".

| Attribute | Meaning |
|---|---|
| `#[Requires('expr')]` | What callers must respect: the proof assumes it on entry, and a call from verified code is checked against it. |
| `#[Ensures('expr')]` | What the method promises when it returns. |
| `#[Throws(E::class, when: 'expr')]` | The method throws `E` if and only if `expr` holds on entry. Any other `throw` must be unreachable. |
| `#[Pure]` | Opt in with no contract: only the automatic checks (overflow, division by zero, `match`, missing `return`...) run. |

## It works on your DTOs, not only on numbers

Real signatures take objects. Readonly data classes (the usual DTOs and value objects, `final` or with a `final` constructor that promotes their properties) can be parameters, return types and nested fields, nullable or not. Their PHPDoc ranges are the domain of the proof. A class may also hold properties the proof does not read, such as the state of a framework base class (`Spatie\LaravelData\Data` is one example): the `public readonly` properties of a supported type are the model, and reading any other property is rejected.

```php
final readonly class Cart
{
    public function __construct(
        /** @var int<0, 100000000> */ public int $subtotalCents,
        public ?Coupon $coupon,
    ) {}
}

final readonly class Coupon
{
    public function __construct(
        /** @var int<1, 100> */ public int $percent,
    ) {}
}

final readonly class Receipt
{
    public function __construct(
        /** @var int<0, 100000000> */ public int $discountCents,
        /** @var int<0, 100000000> */ public int $totalCents,
    ) {}
}
```

A flat 5.00 welcome bonus is added on top of the coupon percentage:

```php
#[Ensures('$result->totalCents >= 0')]
public static function receipt(Cart $cart): Receipt
{
    if ($cart->coupon === null) {
        return new Receipt(0, $cart->subtotalCents);
    }

    $discount = intdiv($cart->subtotalCents * $cart->coupon->percent, 100) + 500;

    return new Receipt($discount, $cart->subtotalCents - $discount);
}
```

```
Postcondition #[Ensures('$result->totalCents >= 0')] can be violated.
Counterexample: $cart = Cart(subtotalCents: 0, coupon: Coupon(percent: 100)) => $result = Receipt(discountCents: 500, totalCents: -500)
```

The prover builds the whole object graph of the counterexample: an empty cart with a 100% coupon gets a negative total. The complete version, with a minimum spend and an optional coupon, is [`Checkout.php`](examples/RealWorld/Checkout.php).

## What you can verify today

The verified subset is small on purpose, so that the answer is a real proof and not a guess:

- `int`, `bool`, `string` (as bytes), enums (pure and backed), nullable versions of all of these, and readonly data classes
- `float`, modelled exactly as IEEE 754: the prover knows that `0.1 + 0.2 !== 0.3`, and that regrouping a sum changes the result
- `if` / `elseif` / `else`, `match`, early `return`, local variables, null checks that narrow the type, and guards that throw (with `new` or a static factory), declared with `#[Throws(E::class, when: '...')]` (a `throw` that nothing declares must be unreachable, as in the `default` arm of an exhaustive `match`)
- enum and value-object code as it is usually written: `self::Case`, class constants, `$this` in the methods of enums (`match ($this)`) and readonly data classes, and calls to other methods, in the same file or in another one, such as `$this->level() <= $other->level()`, or `$this->helper()` in a final service without properties, or a static helper whose `#[Requires]` is checked at the call (the proof is redone when a file it read changes), `new` of a data class declared in another file, and calls to guards that throw
- `foreach` over a `list<T>`, by value, with or without the key, `break`, `continue` and early `return` in the body, accumulation into local variables and result lists built with `$out[] = ...`, and `array_sum()` over a `list<int>`: the loop is unrolled up to the bound that `#[Requires('count($lines) <= 20')]` gives, and a list that can be longer is reported as `contracts.loopBound` instead of being assumed away, so "the parts of a discount split add up to the discount" can be proven over the lines of an order
- `list<T>` and array shapes such as `array{net: int, vat: int}`, as parameters, results and properties: `count()`, `$list[$i]` with a proof that the key exists (`contracts.undefinedKey`), `$shape['net']`, literals, `[...$list, $x]`, appending to a list built in the method, `===` on lists, and element ranges such as `list<int<0, 100>>` as assumptions on inputs and goals on results
- arithmetic, comparisons, `min`, `max`, `abs`, `intdiv`, `in_array` over a list of cases or scalars, or over a `list<T>` (`strict: true` for strings), and the common string checks (length, prefix, suffix, containment, concatenation, with an `int` written in decimal in `.`, interpolation and `(string)`)

Not yet: `while`, `for` and `do` (they need a termination argument), `foreach` by reference or over anything but a list, `array_map()` and the other array functions, loops over lists of any length (they need an invariant), `try`/`catch`, recursion, calls through an interface or an abstract method, arrays with arbitrary keys (`array<string, int>`), lists of lists, union types (`int|float`, `float|string`, `list<float|string>`), dates (`DateTimeInterface`, Carbon), `round()` on floats, library objects such as Eloquent models and `Brick\Money`, mutable state (the state of a framework base class can stay in a data class, but a verified method cannot read it), and `float` or `bool` in a string. Anything outside the subset is reported as `contracts.unsupported` with the reason, never silently skipped. Floating-point multiplication of two variables can be too slow to decide, so money is better kept as integer cents and rates as basis points. See [Supported types](docs/types.md) for what each type can do, and [Limits and roadmap](docs/limits-and-roadmap.md) for what a green run guarantees and what comes next.

## What it costs, and when Z3 gives up

Only the methods that carry a contract go through Z3, and most of them take a fraction of a second. A rule that multiplies two variables, divides by a variable inside a loop, or works on floats can be slow or undecidable: the answer is then `contracts.unknown`, never a pass.

- **Limits.** `contracts.timeoutMs` bounds one check (10 s) and `contracts.methodBudgetMs` bounds a method (60 s). `contracts.runBudgetMs` can bound a whole run.
- **Undecided checks are remembered.** A check that stayed `unknown` is not asked again with the same limits, so a project with a few hard rules does not pay for them on every run.
- **Seeing it coming.** `contracts.costWarnings` reports `contracts.costly` on methods whose structure makes `unknown` likely, and `contracts.timingReport` writes the measured time of each method to a file.
- **Continuous integration.** Keep the proof cache directory between runs.

The details, with the configuration table and advice for CI, are in [Getting started](docs/getting-started.md#cost) and [When Z3 gives up](docs/writing-contracts.md#when-z3-gives-up).

## Learn more

| | |
|---|---|
| [Getting started](docs/getting-started.md) | Install, first proof, configuration |
| [Writing contracts](docs/writing-contracts.md) | The attributes, the contract language, every diagnostic |
| [Supported types](docs/types.md) | `int`, `float`, `string`, enums, nullables, data classes, lists, array shapes, with the real bugs found in each |
| [Real-world examples](docs/real-world-examples.md) | Pagination, VAT, installments, workflows, rate limits, uploads, checkout and more: thirty-two rules, each with the bug the prover found |
| [How it works](docs/how-it-works.md) | From PHP to SMT-LIB to Z3 |
| [Limits and roadmap](docs/limits-and-roadmap.md) | What is guaranteed, what is next |

To run the bundled examples yourself:

```bash
composer install
php bin/phpstan-contracts-install-z3
vendor/bin/phpstan analyse
```

The run reports 79 errors, all intended: 74 from this extension (the examples that show a refuted rule, an undecided one or a rejected construct) and 5 from PHPStan's own rules on the deliberately broken methods (`argument.type` twice, `return.missing`, `return.type`, `match.unhandled`). It takes about two minutes with a cold cache. Every other annotated method is proven.

## Status

Early MVP, tested with Z3 5.4.1, PHP 8.3 to 8.5 and PHPStan 2.3 and later. The CI runs the whole suite and the examples on Linux (PHP 8.3, 8.4, 8.5), Windows and macOS (PHP 8.5). Feedback and real-world contracts are very welcome: open an issue.

## License

MIT
