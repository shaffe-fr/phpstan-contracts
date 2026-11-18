# Getting started

## Requirements

- PHP 8.3 or later
- PHPStan 2.1 or later
- A `z3` executable. The extension is tested with Z3 5.4.1.

## Install Z3

The extension runs Z3 as a child process and feeds it a script on standard input. It needs an executable it can start, not a PHP binding.

Pick one of these. None of them needs Python.

### Download the official build

```bash
vendor/bin/phpstan-contracts-install-z3
```

The command downloads the official Z3 5.4.1 release from the Z3 GitHub releases for your OS and CPU (Windows, Linux and macOS, x64 and arm64), unpacks the executable into `.z3/` inside the package, and runs it once to check that it starts. The extension then finds it by itself, so there is nothing to configure. It needs `ext-zip`, `ext-openssl` and `allow_url_fopen`.

The archive is only accepted if its SHA-256 matches a hash written in the package source, so a compromised download or redirect cannot install anything else. The command never runs by itself: Composer does not run scripts of dependencies, and nothing is downloaded unless you run it. Run it again after `composer update` has replaced the package directory, or use `--dir=<path>` to install somewhere that survives, then set `parameters.contracts.z3Binary` to the path it prints.

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

The lookup order is: the configured path, then the build installed by `phpstan-contracts-install-z3` (only when `z3Binary` is left at its default), then `PATH`.
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
```

| Parameter | Default | Meaning |
|---|---|---|
| `z3Binary` | `z3` | Executable name, looked up in `PATH`, or an absolute path. At the default value, the build installed by `phpstan-contracts-install-z3` takes precedence over `PATH`. |
| `timeoutMs` | `10000` | Time Z3 may spend on each individual check. When it runs out, the check is reported as `contracts.unknown`. |

If the executable cannot be found, every checked method reports `contracts.solver` with the name that was looked up.

## Cost

Each checked method starts one Z3 process. On the Windows machine used for this documentation that costs about 0.2 s per method with a cold PHPStan cache, of which translating the PHP is under 1 ms. PHPStan's result cache skips files that did not change, so a second run on unchanged code does not start Z3 again. A method that does floating-point arithmetic is slower, from about a second to several seconds, because the solver reasons about every bit of the numbers (see [what is slow](types.md#what-is-slow)).

## Silencing a diagnostic

Every diagnostic has an identifier (listed in [Writing contracts](writing-contracts.md#diagnostics)), so PHPStan's standard `ignoreErrors` works. Use it for a deliberate exception, for example an overflow you accept:

```neon
parameters:
	ignoreErrors:
		-
			identifier: contracts.overflow
			path: src/Legacy/*
```
