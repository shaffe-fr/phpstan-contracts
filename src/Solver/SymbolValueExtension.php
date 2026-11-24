<?php

declare(strict_types=1);

namespace PhpstanContracts\Solver;

use PhpParser\Node\Name;
use PHPStan\Analyser\ResultCache\ResultCacheValueExtension;
use PHPStan\Reflection\ReflectionProvider;

/**
 * Whether a function or a constant exists, for the result cache: an unqualified name in a
 * namespace means the namespaced function or constant when there is one, and the global one
 * otherwise, so creating `App\abs()` changes what a proof reading `abs()` in `App` means.
 *
 * Keys are `function:<lowercase fully qualified name>` and `constant:<fully qualified name>`,
 * recorded by {@see \PhpstanContracts\Translate\Lookups}.
 */
final class SymbolValueExtension implements ResultCacheValueExtension
{
    public function __construct(private readonly ReflectionProvider $reflection)
    {
    }

    public function getValue(string $key): string
    {
        [$kind, $name] = array_pad(explode(':', $key, 2), 2, '');
        if ($name === '') {
            return 'missing';
        }
        $found = match ($kind) {
            'function' => $this->reflection->hasFunction(new Name\FullyQualified($name), null),
            'constant' => $this->reflection->hasConstant(new Name\FullyQualified($name), null),
            default => false,
        };

        return $found ? 'found' : 'missing';
    }

    public function keyToResultCache(string $key): string
    {
        return $key;
    }

    public function keyFromResultCache(string $storedKey): string
    {
        return $storedKey;
    }
}
