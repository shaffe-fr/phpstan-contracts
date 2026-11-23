# phpstan-contracts

A PHPStan extension that **proves** business rules written as contracts, instead of sampling them with tests.

You annotate a pure PHP method with `#[Requires]` and `#[Ensures]`. The extension translates the method body and the contracts to SMT-LIB and asks the [Z3](https://github.com/Z3Prover/z3) solver whether any input can break a contract. Either Z3 proves that none can, and PHPStan stays silent, or it returns a concrete input that breaks the rule, and PHPStan reports it as an error.

```php
/**
 * @param int<0, 100000000> $totalItems
 * @param int<1, 1000> $perPage
 */
#[Ensures('$result * $perPage >= $totalItems')]
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

A unit test checks a handful of examples. A proof covers every input in the declared domain, including the ones nobody thought of: negative amounts, the exact multiple, the integer that overflows.

## What it gives you

- **Exhaustive checking** of rules over `int`, `float`, `bool`, `string`, enums, nullable values and readonly data classes, in methods that use `if`/`elseif`/`else`, `match`, early returns, local variables, class constants and `self::`. In enums and readonly data classes, instance methods can read `$this`. `float` is modelled exactly as IEEE 754, so `0.1 + 0.2 === 0.3` is refuted like any other false statement.
- **Automatic safety checks** on those methods: no integer overflow, no division by zero, no `UnhandledMatchError`, no `ValueError` from `Enum::from()`, no path without a `return`, and a returned value that its declared type allows.
- **Concrete counterexamples** with the line of the contract, the values of every parameter, the returned value and the `return` statement that produced it.
- **A contract that cannot be silently weakened** by a refactoring: the proof is redone on every PHPStan run, and a refactoring that breaks the rule fails the build.

## What it does not do (yet)

The verified subset is deliberately small. There are no loops, no calls to methods of other files, no arrays and no mutable objects, and the extension only checks that callers respect a method's `#[Requires]` for calls within a file. Floating-point multiplication of two variables is often too slow to decide. See [Limits and roadmap](limits-and-roadmap.md) for the full list and for which limits come from mathematics and which are simply not built yet.

## Documentation

| Page | Read it to |
|---|---|
| [Getting started](getting-started.md) | install Z3 and the extension, run your first proof, configure the timeout |
| [Writing contracts](writing-contracts.md) | learn the attributes, the contract language, the supported PHP subset and every diagnostic |
| [Supported types](types.md) | see what each type can do (`float`, `string`, nullables, enums, data classes), with real bugs |
| [Real-world examples](real-world-examples.md) | see twenty-two business rules proved or refuted, with the bugs the prover found |
| [How it works](how-it-works.md) | understand the SSA translation, the safety obligations and the Z3 protocol |
| [Limits and roadmap](limits-and-roadmap.md) | know what a green run guarantees, and what is next |
| [Testing](testing.md) | run the test suite, add a case, and see what each suite guarantees |

## Running the examples

From the root of this repository, with `z3` on the `PATH`:

```bash
composer install
vendor/bin/phpstan analyse
```

The repository's `phpstan.neon.dist` sets `contracts.timeoutMs` to 30000 so that the float examples have a wide margin on a slow machine. The run takes about a minute and a half with a cold cache, because the two methods of `SolverLimits.php` wait for the full 30 seconds and the float methods take several seconds each. It reports the diagnostics of the methods that are meant to fail: the `*Broken`, `*Naive` and `*Unbounded` methods and `SolverLimits` in [`examples/RealWorld`](../examples/RealWorld), the cases in `examples/Rejected.php` that show each kind of rejection, the late-bound `static::` constant in `examples/CartLimits.php`, and one method in `examples/Pricing.php`. Every other annotated method in those files is proven. A few diagnostics come from PHPStan's own rules and not from this extension (`argument.type` twice, `return.missing` and `match.unhandled`: 4 of the 63 errors of the run), on the deliberately broken methods.

Of the 81 methods of `examples/RealWorld` that reach the solver, 47 are proven, 32 are refuted with a counterexample and 2 are not decided.

## Status

Early MVP. The examples in this documentation are real code in [`examples/RealWorld`](../examples/RealWorld), and every output shown here was produced by running `phpstan analyse` on that code with Z3 5.4.1, PHP 8.5 and PHPStan 2.3. The package targets PHP 8.3 and later.
