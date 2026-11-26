# Getting started

## Requirements

- PHP 8.3 or later
- PHPStan 2.3 or later
- A `z3` executable. The extension is tested with Z3 5.4.1.

## Install Z3

The extension runs Z3 as a child process and feeds it a script on standard input. It needs an executable it can start, not a PHP binding.

Pick one of these. None of them needs Python.

### Download the official build

```bash
vendor/bin/phpstan-contracts-install-z3
```

The command downloads the official Z3 5.4.1 release from the Z3 GitHub releases for your OS and CPU (Windows, Linux and macOS, x64 and arm64), unpacks the executable into a per-user cache shared by every project and every git worktree, and runs it once to check that it starts. The extension then finds it by itself, so there is nothing to configure. The cache is `%LOCALAPPDATA%\phpstan-contracts\z3\<version>` on Windows, and `$XDG_CACHE_HOME/phpstan-contracts/z3/<version>` or `~/.cache/phpstan-contracts/z3/<version>` elsewhere, macOS included. If the environment names none of these, the command refuses to guess and asks for `--dir`. It needs `ext-zip`, `ext-openssl` and `allow_url_fopen`.

The archive is only accepted if its SHA-256 matches a hash written in the package source, so a compromised download or redirect cannot install anything else. The command never runs by itself: Composer does not run scripts of dependencies, and nothing is downloaded unless you run it. The cache is outside `vendor/`, so `composer update` does not remove it and a second checkout with its own `vendor/` reuses it. `--force` reinstalls over an existing version, and `--dir=<path>` installs under `<path>/<version>` instead; set `parameters.contracts.z3Binary` to the path it prints, since the extension does not look in a custom directory. Earlier versions installed into `.z3/` inside the package: such an install keeps working, but a new install goes to the cache.

The official Linux builds require a recent glibc (2.38 on arm64, 2.39 on x64, so Ubuntu 24.04 or newer). On an older distribution or on Alpine, the command reports that the binary does not start, and you should use your package manager.

### Use a system package

```bash
apt install z3
brew install z3
winget install Z3Prover.Z3
```

The extension uses the `z3` found in `PATH` when nothing was installed with the command above. It is tested with Z3 5.4.1; other recent versions should behave the same, but the output format of counterexamples is not guaranteed.

### Point to any executable

Set `parameters.contracts.z3Binary` to a name looked up in `PATH`, or to an absolute path (see [Configuration](#configuration)).

The lookup order is: the configured path, then the per-user cache, then the legacy `.z3/` inside the package when it exists, then `PATH`. The cache and the legacy directory are only searched when `z3Binary` is left at its default.
## Install the extension

The package is not published on Packagist yet. Install it from a local or VCS repository:

```bash
composer config repositories.phpstan-contracts path ../phpstan-contracts
composer require --dev shaffe-fr/phpstan-contracts
```

If you use `phpstan/extension-installer`, the extension is registered automatically. Otherwise include it in `phpstan.neon`:

```neon
includes:
	- vendor/shaffe-fr/phpstan-contracts/extension.neon
```


## Your first proof

Create a class with one annotated method:

```php
<?php

declare(strict_types=1);

namespace App\Billing;

use PhpstanContracts\Attribute\Ensures;

final class Pagination
{
    /**
     * @param int<0, 100000000> $totalItems
     * @param int<1, 1000> $perPage
     */
    #[Ensures('$result >= 0')]
    #[Ensures('$result * $perPage >= $totalItems')]
    public static function pageCount(int $totalItems, int $perPage): int
    {
        return intdiv($totalItems + $perPage - 1, $perPage);
    }
}
```

Run PHPStan as usual:

```bash
vendor/bin/phpstan analyse
```

No error means that Z3 proved, for every `$totalItems` in `0..100000000` and every `$perPage` in `1..1000`, that:

- the addition `$totalItems + $perPage - 1` cannot overflow,
- the divisor `$perPage` is never zero,
- the result is never negative, and
- the pages returned can hold every item.

Now break it. Replace the body with `return intdiv($totalItems, $perPage);` and PHPStan reports:

```
Postcondition #[Ensures('$result * $perPage >= $totalItems')] can be violated.
Counterexample: $totalItems = 1, $perPage = 1000 => $result = 0 (return at line 19).
```

The values are a counterexample Z3 found, not an example you wrote: one item and a page size of 1000 give zero pages, so the item has nowhere to be displayed.

## Which methods are checked

Only methods that carry at least one of `#[Requires]`, `#[Ensures]` or `#[Pure]`. Every other method is left alone, so you can adopt the extension one rule at a time.

A checked method must stay inside the [supported subset](writing-contracts.md#the-supported-php-subset). Anything outside it is reported as `contracts.unsupported`, with the reason and the line.

## Configuration

```neon
parameters:
	contracts:
		z3Binary: z3
		timeoutMs: 10000
		loopUnroll: 10
		maxLoopUnroll: 100
		cacheDirectory: %tmpDir%/contracts
		parallelism: null
		methodBudgetMs: 60000
		runBudgetMs: 0
		rememberUndecided: true
		costWarnings: false
		timingReport: null
```

| Parameter | Default | Meaning |
|---|---|---|
| `z3Binary` | `z3` | Executable name, looked up in `PATH`, or an absolute path. At the default value, the build installed by `phpstan-contracts-install-z3` (per-user cache, then legacy `.z3/`) takes precedence over `PATH`. |
| `timeoutMs` | `10000` | Time Z3 may spend on each individual check. When it runs out, the check is reported as `contracts.unknown`. |
| `loopUnroll` | `10` | Iterations unrolled for a `foreach` over a list that has no bound in a `#[Requires]`, such as `count($lines) <= 50`. A list that can have more elements is reported as `contracts.loopBound`. From `1` to `maxLoopUnroll`. See [Loops](writing-contracts.md#loops). |
| `maxLoopUnroll` | `100` | The most iterations that one loop, or the product of the bounds of nested loops, may be unrolled. A method over the budget is rejected before Z3 runs. From `1` to `10000`. |
| `cacheDirectory` | `%tmpDir%/contracts` | Where the answers of Z3 are remembered between runs. `null` turns the cache off. See [Cost](#cost). |
| `parallelism` | `null`, which is `min(4, number of CPU cores)` | The most Z3 processes that run at once when a check that Z3 left `unknown` is asked again once per length of the list that the loop unrolls (see [Loops](writing-contracts.md#loops)). From `1`, which runs the cases one after another. PHPStan already runs several workers, so the total number of Z3 processes can be workers x `parallelism`: lower it on a small machine or a busy CI runner. |
| `methodBudgetMs` | `60000` | Wall-clock time that one method may spend with Z3: the main run, the retries and the split by length. `0` removes the limit. A check that the budget stopped before it was tried in full is reported as `contracts.unknown`, never as proven. A method with many hard checks costs at most this, plus the time of a process that is being stopped. The one extra question that shortens a long counterexample so it can be printed is not counted in it: it runs only for a check that already failed, and falls back to the long form if it fails. |
| `runBudgetMs` | `0`, which is no limit | Wall-clock time that Z3 may spend in one PHPStan process, all methods together. Once it is spent, each method that would need Z3 gets one `contracts.unknown` saying so, until the next run. PHPStan workers do not share the count, so the total is at most the number of workers x this value. |
| `rememberUndecided` | `true` | Remembers, in `cacheDirectory`, a check that stayed `unknown` after everything that is tried, under the limits of the run (`timeoutMs`, `methodBudgetMs`), the script and the Z3 build. The next run does not ask Z3 again and reports `contracts.unknown` saying so. A check that a budget cut short is never remembered. The memory is local, and what Z3 decides in a time limit depends on the machine: clear the cache directory or change a limit to try again. |
| `costWarnings` | `false` | Reports `contracts.costly` on a method whose structure makes `contracts.unknown` likely: a loop unrolled over several divisions or modulos by a variable. It reads the code only, never a clock, so it is the same on every run. PHPStan has no warning level, so it is an error like the others: ignore it by identifier with `ignoreErrors`. It predicts an order of magnitude and a risk, not seconds, and never stops a method from being verified. |
| `timingReport` | `null` | A file path. When set, each verified method appends one JSON line with its predicted structure, the measured milliseconds of the main run, the retry and the split, its verdicts and the state of its budget. This is the only place where measured time appears. The file is appended to under a lock, so parallel workers can share it: delete it before a run to start a new report. |

If the executable cannot be found, every checked method reports `contracts.solver` with the name that was looked up.

## Cost

Each checked method starts one Z3 process. On the Windows machine used for this documentation that costs about 0.2 s per method with a cold PHPStan cache, of which translating the PHP is under 1 ms. PHPStan's result cache skips files that did not change, so a second run on unchanged code does not start Z3 again. A method that calls code from other files is analysed again when any file its proof read changes, even if only a method body changed there, so the proof never outlives the code it was made from.

When a file does change, the proof cache still saves the methods that did not: the answers of Z3 are stored in `cacheDirectory`, keyed by the exact script that was sent and by the Z3 build. A method whose script is identical is answered from disk, even in another file or after a PHPStan cache reset. Only settled answers (`sat` and `unsat`) are stored as proofs, never `unknown`, so raising `timeoutMs` always gives a hard method another chance. The one other thing stored is the memory of undecided checks (`rememberUndecided`), kept apart from the proofs and never read as one. An entry that cannot be read back exactly is ignored. The cache lives in PHPStan's temporary directory and is trusted like it, so keep it out of reach of anyone who should not be able to turn a failing proof green. Entries are small files that are never deleted automatically: remove the directory to clean up. On the repository's `examples` (61 scripts, 30 s timeout), a run with both caches cold took 87 s. With only the proof cache warm it took 64 s, and the time that remains is the methods Z3 cannot decide, which are asked again every time. With both caches warm it took 1.4 s. A method that does floating-point arithmetic is slower, from about a second to several seconds, because the solver reasons about every bit of the numbers (see [what is slow](types.md#what-is-slow)).

### Time budgets and continuous integration

Only `sat` and `unsat` are verdicts. A check that was not tried, that a budget cut short, that was killed, or that an earlier run left undecided is `unknown`, never proven and never refuted.

- `timeoutMs` limits one check. `methodBudgetMs` limits one method: the main run, the retries and the split by length together. `runBudgetMs` limits all the methods of one PHPStan process. A budget stops Z3 and the checks it did not reach are reported as `contracts.unknown`.
- A check that a budget cut before it was tried in full is never remembered. The next run starts with a fresh budget and skips the checks that earlier runs left undecided, so it gets further into the later ones. A project with many methods that need Z3 converges over a few runs instead of failing on the first.
- Keep the proof cache directory (`contracts.cacheDirectory`, `%tmpDir%/contracts` by default) between runs on continuous integration. Without it every run asks Z3 again for every method, and the checks that stay `unknown` are asked again too.
- Timeouts depend on the speed of the machine, and the cache is local. A check that Z3 decides in 8 s on a laptop may be `unknown` after 10 s on a busy runner, and the memory of undecided checks of one machine says nothing about another. Share the cache directory between runners only if they run the same Z3 build and you accept that.
- PHPStan workers do not share the count of `runBudgetMs`: with 4 workers and `runBudgetMs: 120000` Z3 may run for 8 minutes of process time in all. The same holds for `parallelism`, which is per worker: 4 workers x `parallelism: 4` is up to 16 Z3 processes at once. On a runner with few cores, lower `parallelism` rather than raise `timeoutMs`.
- To size the budgets, run once with `timingReport` set and read the measured milliseconds of the slowest methods. Set `timeoutMs` a little above the slowest check you want proven, `methodBudgetMs` to a small multiple of the slowest method, and `runBudgetMs` to what a job may spend, divided by the number of workers. Set `costWarnings` to learn, without running Z3, which methods are likely to stay `unknown`.

## Silencing a diagnostic

Every diagnostic has an identifier (listed in [Writing contracts](writing-contracts.md#diagnostics)), so PHPStan's standard `ignoreErrors` works. Use it for a deliberate exception, for example an overflow you accept:

```neon
parameters:
	ignoreErrors:
		-
			identifier: contracts.overflow
			path: src/Legacy/*
```
