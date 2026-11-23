# Limits and roadmap

## What a green run guarantees

For every input that satisfies the native parameter types, the PHPDoc ranges and every `#[Requires]`, a method that passes (in an enum or a readonly data class, `$this` is one of the inputs):

1. runs without integer overflow, division by zero, unhandled `match`, invalid `Enum::from()` value, unrepresentable `(int)` cast, missing `return` or read of a missing list key,
2. throws an exception only where a `#[Throws]` covers its class and the condition of every `#[Throws]` that covers it holds, and, for a `#[Throws(E::class, when: ...)]` with a condition, throws an `E` whenever the condition holds,
3. returns a value that its declared return type allows, and
4. satisfies every `#[Ensures]` when it returns.

The guarantee is **fail-closed**. A construct the translator does not know is reported as `contracts.unsupported`, a question the solver cannot settle is reported as `contracts.unknown`, and neither is ever treated as a pass.

## What it does not guarantee

- **Most callers are not checked.** A call from a verified method to a method of the same file checks the callee's `#[Requires]` (`contracts.requiresViolated`). Any other call, from ordinary code or from another file, is not checked. PHPDoc ranges are enforced by PHPStan itself at the call sites it analyses, but a precondition expressed only as `#[Requires]` is an assumption of the proof that nobody checks on the caller's side. This is the largest gap, and the first item on the roadmap.
- **PHPDoc ranges are trusted.** They hold wherever PHPStan can see the caller. A caller whose argument is `mixed`, or code that PHPStan does not analyse, can break them.
- **PHPDoc array types are trusted.** A `list<int<0, 100>>` or an `array{net: int, vat: int}` is assumed to hold exactly what it declares: a list whose keys are `0..n-1` in order, with every element in range, and a shape that has its keys. PHPStan checks this at the call sites it analyses, but an array that comes from `json_decode()`, a framework or untyped code can break it. A list or a shape of floats is therefore not accepted as an input, because PHPStan allows an `int` where a `float` is declared in an array and PHP does not convert it.
- **A data class is trusted to hold its declared types.** An instance of a class whose constructor validates its arguments is assumed to hold values of its property types and nothing more. A constructor that throws is never seen by the proof, which is why such a class cannot be built with `new` in a verified method. The same holds for `$this` in a method of such a class.
- **Contracts are verified on concrete methods only.** A contract on an interface method, an abstract method or a property hook is rejected, and an implementation does not inherit the contract of the method it implements.
- **Objects are assumed to be built by their constructor.** Only properties promoted by a constructor that no subclass can skip are modelled, so a modelled property is always set. An object created without its constructor, through `ReflectionClass::newInstanceWithoutConstructor()`, `unserialize()` or a lazy object, can lack one, and reading it then throws an `Error` that the proof does not see.
- **Every file that declares functions or constants is assumed to be known to PHPStan.** An unqualified `abs($x)` or `PHP_INT_MAX` in a namespace calls the function or reads the constant of that namespace when one exists, so the extension rejects it when PHPStan knows such a declaration. A declaration in a file that PHPStan neither analyses nor scans, such as when one file is analysed alone, is not seen. Write `\abs($x)` and `\PHP_INT_MAX` to be independent of the files analysed.
- **Calls and `new` are limited to the same file.** A verified method can call a method declared in the same file, whose body is then translated in place. A call to a method of another file is rejected, because PHPStan's result cache would not redo the proof when only that method's body changes. For the same reason, `new X(...)` is only accepted when the constructor of `X` is declared in the same file: the proof relies on its body being empty. A rule that spans files has to be a single self-contained method, or wait for modular verification.
- **No model of the environment.** Time, randomness, I/O, memory and execution time are outside the verified subset. Methods that need them are rejected, not approximated.
- **The translator is not itself verified.** It is tested (see [Testing](testing.md)): its PHP semantics are compared with PHP, every diagnostic has a fixture, and every counterexample is replayed in PHP. A test suite finds bugs, it does not prove their absence, and the answer of Z3 is taken at face value.
- **No termination argument is needed today**, because loops are rejected. It will be needed the day loops are supported.

## What is still missing, and why

The verified subset covers `int`, `bool`, `float`, `string`, enums, readonly data classes and nullable versions of these. Whether the rest is missing because of mathematics or because of the amount of work is worth separating, and it is mostly work.

### Not a mathematical limit: work that is not done yet

SMT solvers already reason about integers, bit-vectors, floating point, strings, sequences, arrays and algebraic datatypes. The remaining PHP types have a standard encoding. What is missing is the translation, the supported operations and the tests.

| Type or feature | Encoding | What makes it more than a type mapping |
|---|---|---|
| general arrays `array<K, V>`, lists of lists, and loops over lists | an SMT array plus a length for lists, which is done | `list<T>` and array shapes are supported, with the operations that do not iterate (`count()`, reads by index, appending, `in_array()`). Arbitrary keys need the exact PHP rules for key conversion (`"1"` becomes `1`) and for ordering. Anything that visits every element needs a loop. |
| calls to methods of other files, and `new` of classes whose constructor is in another file | an uninterpreted function constrained by the callee's contract | Needs the callee's contract instead of its body, and the call-site check described above. Calls within a file are done, by translating the callee's body, and so is `new` of a class whose constructor is in the same file. |
| `DateTimeImmutable`, `Money` and other library value objects | a record, as for data classes | The class has methods. The methods would need to be modelled or replaced by contracts. |
| `substr()`, `strpos()`, `trim()`, `strtolower()`, `sprintf()` | the string theory has most of the primitives | Each PHP function has its own edge cases (negative offsets, locale, multibyte). Each one is a decision to reproduce its semantics exactly. |
| `round()` | `fp.roundToIntegral` with ties away from zero | The result of `round()` changed between PHP versions, so the encoding would depend on the PHP version. |

### A mathematical limit: undecidable or intractable in general

| Limit | Consequence |
|---|---|
| Multiplying two variables is non-linear integer arithmetic, which is undecidable. | Z3 may answer `unknown`. Bounded ranges make most real cases work, as the examples show, and an example of a failing case is [documented](real-world-examples.md#when-the-solver-gives-up). |
| Floating-point multiplication and division are decidable but expensive, because the solver reasons about every bit of the mantissa. | A method with a few operations is decided in seconds. Two float variables multiplied together may not be decided at all within a reasonable time, see [what is slow](types.md#what-is-slow). |
| Loops. Whether a loop terminates, and what it computes, cannot be decided in general. | Unbounded loops need an invariant and a variant supplied by the author. Bounded loops can be unrolled, which is decidable but multiplies the size of the problem. |
| Quantifiers, such as "for all elements of the array", combined with arithmetic. | Undecidable in general. Solvers use heuristics and answer `unknown` when they fail. |
| String constraints that mix concatenation, length and replacement. | Hard, and undecidable for some combinations of operations. The operations supported today (length, prefix, suffix, containment, concatenation) are the ones that string solvers handle best. |

These limits are the reason a verified subset is small by design, and the reason the extension answers `unknown` rather than guessing.

### A language limit: PHP is not a mathematical language

Even when the mathematics is fine, PHP semantics must be reproduced exactly:

- Integers overflow into floats. The extension turns this into a proof obligation instead of modelling it.
- `==` juggles types, strings convert to numbers, and `if ($x)` has a truthiness rule. The extension rejects them.
- Objects can alias each other, and arrays are copied lazily and can hold references. Mutable state needs a heap model, which is a different and heavier technique. Immutable values avoid the problem, which is why only the `public readonly` properties of an object are modelled, and reading any other property is rejected.
- Exceptions, destructors, `__get` and `__call` run code that is invisible at the call site. That is why `new` is rejected for a class with a destructor.
- A `yield` anywhere in a body, even in code that never runs, turns the method into a generator. A body that contains one is rejected.

The whitelist exists for this reason. Each item that is added is a deliberate decision to reproduce one PHP semantics exactly.

### The practical answer

Most business rules fit a core of amounts, counts, flags, statuses, short strings and small immutable objects. Extracting that core into a method over such values, as shown in [Structuring real code](real-world-examples.md#structuring-real-code-for-verification), covers the rules in the examples. What does not fit, such as a rule that sums the lines of an order, is the next real frontier: lists are supported, but a rule that visits every element needs loops.

## Roadmap

These are directions in order of value for the effort, not commitments or dates.

1. **Modular verification.** Check `#[Requires]` at every call site, and let a verified method call verified methods of other files using their contracts. Calls within a file already work, by translating the callee's body, and check the callee's `#[Requires]`. This closes the largest gap above.
2. **A persistent Z3 process.** One long-lived process per PHPStan worker, reset between methods, to remove most of the process start-up cost that dominates a run for integer, string and data class methods (see [How it works](how-it-works.md#what-runs-where)). The cache of proofs keyed by script hash, which is the complement, is done: see [Cost](getting-started.md#cost).
3. **Bounded loops over lists**, as an unrolling with an explicit bound, then with `#[Invariant]`. Lists, array shapes, reads by index, appending and `in_array()` are done; `foreach`, `array_map()`, `array_sum()` and the other array functions are the next step.
4. **Calls to methods of data classes and enums declared in other files**, through their own contracts, which follows from modular verification. Within a file, `$this->level()` and `$other->level()` already work.
5. **More string functions**, one at a time, each with its edge cases decided.
6. **Cross-checking with a second solver** on `unsat` results, to reduce the trust placed in one solver.
