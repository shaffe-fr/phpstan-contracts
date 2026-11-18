<?php

declare(strict_types=1);

namespace PhpstanContracts\Translate;

use PhpstanContracts\Exception\VerificationRejected;
use PhpParser\Node\Name;
use PHPStan\Reflection\ReflectionProvider;

/**
 * Contract strings are parsed outside the file's name-resolution pass, so `use`
 * imports are unavailable. A short name resolves first against the types in the
 * method signature, then against the current namespace, then globally.
 */
final class ClassNameResolver
{
    /**
     * @param array<string, string> $signatureClasses short name => fully qualified name
     */
    public function __construct(
        private readonly ReflectionProvider $reflectionProvider,
        private readonly ?string $namespace,
        private readonly array $signatureClasses,
    ) {
    }

    public function resolve(Name $name, int $line): string
    {
        if ($name->isFullyQualified()) {
            return $name->toString();
        }

        $written = $name->toString();
        if (isset($this->signatureClasses[$written])) {
            return $this->signatureClasses[$written];
        }

        $candidates = $this->namespace !== null && $this->namespace !== ''
            ? [$this->namespace . '\\' . $written, $written]
            : [$written];

        foreach ($candidates as $candidate) {
            if ($this->reflectionProvider->hasClass($candidate)) {
                return $this->reflectionProvider->getClass($candidate)->getName();
            }
        }

        throw new VerificationRejected(
            sprintf('Cannot resolve class %s; write its fully qualified name.', $written),
            $line,
            'contracts.invalidContract',
        );
    }
}
