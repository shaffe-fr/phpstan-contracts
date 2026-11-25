<?php

declare(strict_types=1);

namespace PhpstanContracts\Examples\RealWorld;

/**
 * The exception of a guarded code range, built by named factories as domain exceptions
 * often are. A verified method throws it with `throw CodeRangeException::...(...)`: the
 * proof reads the signature of the factory, not its body.
 */
final class CodeRangeException extends \DomainException
{
    public static function outOfReservedRange(int $webId, int $code, int $offset, int $end): self
    {
        return new self(sprintf('Web id %d gives the code %d, outside the range %d to %d.', $webId, $code, $offset, $end));
    }
}
