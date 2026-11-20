# Testing

A verifier that is wrong is worse than no verifier: a false proof gives confidence that nothing justifies. The test suite exists to make that unlikely, and each part of it answers one question.

## Running it

```bash
composer install
php bin/phpstan-contracts-install-z3   # most tests run the solver
composer analyse                    # the package, and its tests, analysed with its own extension
composer test                       # everything except the examples, about a minute
composer test:examples              # what the prover says about each example, under a minute
```

Tests that need Z3 skip themselves when it is not installed. Set `CONTRACTS_REQUIRE_Z3=1` to make them fail instead, which is what the CI does, so that a missing solver can never look like a green run.

## What each suite checks

| Suite | Needs Z3 | Question it answers |
|---|---|---|
| `unit` | no | Do the term constructors, the S-expression reader, the value formatter, the script renderer, the domain builder, the encoding and the contract reader behave exactly as specified? |
| `solver` | yes | Does Z3 give, for each float, string and conversion operation, **the same answer as PHP**? Is every answer of Z3 read correctly, including an unknown one? |
| `rule` | yes | For each supported construct, does the real rule, wired by the real `extension.neon`, prove what is true, refute what is false, and reject what is outside the subset with the right message? |
| `docs` | no | Is every line of code quoted in the documentation still in `examples/`, does every link and anchor resolve, and is every diagnostic identifier documented? |
| `examples` | yes | Does each example come out as the documentation says: proven, refuted, or rejected? |

## The package verifies itself

`phpstan.src.neon` includes the extension, so `composer analyse` runs the package on its own code. Two things are proven that way, and each replaced example-based tests.

**The time limit of a Z3 run.** `Z3Runner` kills the solver after a delay. If that delay came before the solver's own per-check timeouts, a slow but honest `unknown` would be reported as a solver failure. The formula is a pure function over ints, so it carries a contract:

```php
/**
 * @param int<1, 86400000> $perCheckMs at most a day
 * @param int<1, 100000> $checkCount
 */
#[Ensures('$result >= 10')]
#[Ensures('$result * 1000 >= $perCheckMs * $checkCount + 10000')]
#[Ensures('$result * 1000 < $perCheckMs * $checkCount + 11000')]
public static function processSeconds(int $perCheckMs, int $checkCount): int
{
    return intdiv($perCheckMs * $checkCount + 999, 1000) + 10;
}
```

Replacing the ceiling by a floor, the classic mistake, is reported at once:

```
TimeLimits.php:25: Postcondition #[Ensures('$result * 1000 >= $perCheckMs * $checkCount + 10000')] can be violated.
Counterexample: $perCheckMs = 1, $checkCount = 1 => $result = 10 (return at line 29).
```

Removing the `PHPDoc` ranges is reported as an overflow, which is why `Z3Runner` now checks its arguments and refuses a timeout that is not between 1 ms and a day.

**The laws of PHP's integer division.** `intdiv()` and `%` are translated to SMT definitions that differ from SMT's own `div` and `mod`. Instead of comparing them with PHP on a sample of pairs, the package proves four laws for every pair of ints:

```php
/**
 * The four laws that define truncated division, for every pair of ints. Only one
 * (quotient, remainder) pair satisfies all four, so proving them for `intdiv()`
 * and `%` proves that the translation of both is PHP's.
 */
#[Requires('$b !== 0 && ($a !== PHP_INT_MIN || $b !== -1)')]
#[Ensures('intdiv($a, $b) * $b + $a % $b === $a')]
#[Ensures('abs($a % $b) < abs($b)')]
#[Ensures('$a % $b === 0 || ($a % $b < 0) === ($a < 0)')]
#[Ensures('abs(intdiv($a, $b) * $b) <= abs($a)')]
public static function divisionAndModuloObeyTheLawsOfTruncatedDivision(int $a, int $b): bool
{
    return true;
}
```

Because those laws single out one quotient and one remainder, the proof shows that the translation is PHP's **provided PHP obeys them**. That premise is about PHP and not about the package, so it is checked on PHP itself, without the solver, by `tests/Unit/PhpIntegerDivisionTest.php`. The proof catches what a sample can miss: with the rounding of `div` instead of truncation, it reports `$a = -9223372036854775807, $b = 2`, at the edge of the range.

### Why not more

Of all the methods of `src/`, one has only scalar parameters and a scalar result: a private helper that tests for a space in a string. The package is a compiler. Its code builds strings, arrays and objects, which is what the verified subset leaves out: no arrays, no calls to methods of other files, a handful of string functions, no bitwise operators. So most of the tests stay, for a reason that depends on what they check:

| Tests | Could a contract replace them? |
|---|---|
| Term rendering (`SmtTest`), S-expressions, value formatting, script layout | No. The result is a string or a list assembled with `sprintf`, `implode` and regular expressions. |
| Encoding, domains, contract reader | No. They manipulate objects and arrays. |
| The semantics of floats, strings and conversions (`SemanticsTest`) | **No, by design.** See below. |
| Division and modulo of ints | **Yes, and replaced** by the proof above. |
| The rule over fixtures and examples | No: they are the proofs' own acceptance tests. |

The semantics tests have to stay because verifying the translator with the translator is circular. A contract is proven relative to the model of PHP that the translator embeds. If that model of floats were wrong, the package would "prove" things about itself on a wrong foundation and report nothing. The only independent reference is PHP's actual behaviour, which is why the tests compare the model with PHP bit for bit. The integer division is the exception, and only because four laws leave a single answer, so that the comparison with PHP shrinks to checking the laws.

Two things would let more tests be replaced. Modular verification, the first item of the [roadmap](limits-and-roadmap.md#roadmap), would let a method be proven in terms of the contracts of the methods it calls in other files. Bitwise operators, which are not on the roadmap, would allow proving that `Smt::floatLiteral` takes a float apart and `ValueFormatter` puts it back together without loss. Neither is a commitment.

## The semantics tests

`tests/Solver/SemanticsTest.php` is the most important file for trust. The prover reasons about SMT-LIB terms, so the proofs are only worth something if those terms mean what PHP means. For each operation, the test computes the result in PHP, asks Z3 to evaluate the term that the translator emits, and requires the **same bit pattern**:

- The conversions below, and the operations on floats and strings. Integer division is handled differently, see [The package verifies itself](#the-package-verifies-itself).
- Float `+ - * /`, negation, `abs`, `sqrt`, `floor`, `ceil`, the comparisons, on random bit patterns and on `NAN`, both infinities, both zeros, the smallest subnormal and `PHP_FLOAT_MAX`.
- `NAN === NAN` and `0.0 === -0.0`, which differ from SMT's structural equality, and are the reason the translator never uses `=` for `===`.
- `int` to `float` conversion, which rounds for integers beyond 2^53, and `(int)` of a float, which truncates.
- Every one of the 256 byte values as a one-character string, and `strlen`, prefix, suffix, containment and concatenation on strings with NUL bytes and non-ASCII bytes.

The random values come from a fixed seed, so a failure is reproducible.

## The rule tests

Each file in `tests/Fixtures` is a class whose methods are small programs, and each test states the expected outcome of **every method**. An empty list means "proven". Otherwise the entry is a diagnostic identifier, optionally followed by a fragment of its message:

```php
'postconditionViolated' => ['contracts.ensuresViolated: Postcondition #[Ensures(\'$result > 50\')] can be violated'],
'additionOverflows' => ['contracts.overflow: `$a + $b`'],
'sum' => [],
```

Expectations are per method, not per line, so editing a fixture does not break the others. A method without an expectation, or an expectation without a method, fails the test: nothing in a fixture is left unchecked.

To add a case, add a method to the fixture of the right feature, add its line to the matching test, and run `vendor/bin/phpunit --filter <TestName>`.

The rule tests also cover the failure paths that the examples do not: an invalid contract, a variable that is not defined, a precondition that contradicts itself, an unsupported statement, and a question that Z3 cannot settle within its timeout, which must be reported as `contracts.unknown` and never as a pass.

## Counterexamples are replayed

`tests/Rule/CounterexampleReplayTest.php` takes the methods that the prover refutes, reads the values out of each message, **runs the method in PHP with them**, and checks two things:

1. PHP returns exactly the `$result` that the message announces.
2. The contract is false in PHP for those values.

This is the check that matters most to a user: a counterexample that does not reproduce would send them after a bug that does not exist. The replay covers integers, floats (including `NAN`), strings (including non-ASCII bytes), booleans, nullable values and enums, and calls an enum instance method on the case that the message gives as `$this`.

## Does the suite catch bugs?

It was checked by injecting bugs into the code and running the tests. These ten were each caught:

| Injected bug | Caught by |
|---|---|
| `===` on floats compiled to structural equality | `solver` |
| overflow obligations removed | `rule` |
| `intdiv` rounding toward minus infinity | `solver` |
| a path condition dropped from an obligation | `solver` |
| the `then` value kept at every `if` merge | `rule` |
| PHPDoc ranges of parameters not assumed | `rule` |
| a null check that never narrows | `rule` |
| strings no longer escaped as bytes | `solver` |
| an `unknown` answer read as a proof | `solver` |
| a checked obligation no longer assumed afterwards | `solver` |

That is a sample, not a measurement of coverage.

## What is not covered

- **The installer.** `phpstan-contracts-install-z3` downloads a release and is tested by hand.
- **Other Z3 versions.** The tests run against the version that the installer provides. A different version may print counterexample values differently, which is why the rule tests match identifiers and message fragments and not whole messages.
- **Operating systems.** The suite is developed on Windows and runs in the CI on Linux. macOS is not tested.
- **Performance.** The tests check answers, not times. Only the timeout path is exercised.
