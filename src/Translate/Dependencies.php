<?php

declare(strict_types=1);

namespace PhpstanContracts\Translate;

/**
 * What a proof read besides the analysed file, for PHPStan's result cache: the files,
 * the class names that did not resolve, and the function and constant names looked up.
 */
final readonly class Dependencies
{
    /**
     * @param list<string> $files absolute paths, resolved by realpath() when the file exists
     * @param list<string> $missingClasses class names looked up and not found
     * @param list<string> $symbols `function:<lowercase fqn>` and `constant:<fqn>`, see {@see \PhpstanContracts\Solver\SymbolValueExtension}
     */
    public function __construct(
        public array $files = [],
        public array $missingClasses = [],
        public array $symbols = [],
    ) {
    }

    public function isEmpty(): bool
    {
        return $this->files === [] && $this->missingClasses === [] && $this->symbols === [];
    }

    /**
     * @return list<string>
     */
    public function filesOtherThan(string $file): array
    {
        $file = realpath($file) ?: $file;

        return array_values(array_filter(
            $this->files,
            static fn (string $read): bool => PHP_OS_FAMILY === 'Windows' ? strcasecmp($read, $file) !== 0 : $read !== $file,
        ));
    }
}
