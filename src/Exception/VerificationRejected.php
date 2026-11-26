<?php

declare(strict_types=1);

namespace PhpstanContracts\Exception;

use RuntimeException;

/**
 * The method leaves the verifiable subset (or its contract is malformed).
 */
class VerificationRejected extends RuntimeException
{
    public function __construct(
        string $message,
        public readonly int $errorLine,
        public readonly string $identifier = 'contracts.unsupported',
    ) {
        parent::__construct($message);
    }
}
