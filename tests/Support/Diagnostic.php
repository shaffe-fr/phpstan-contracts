<?php

declare(strict_types=1);

namespace PhpstanContracts\Tests\Support;

final readonly class Diagnostic
{
    public function __construct(
        public int $line,
        public string $identifier,
        public string $message,
    ) {
    }

    public function describe(): string
    {
        return sprintf('%s (line %d): %s', $this->identifier, $this->line, $this->message);
    }
}
