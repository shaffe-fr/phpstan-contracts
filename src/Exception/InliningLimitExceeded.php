<?php

declare(strict_types=1);

namespace PhpstanContracts\Exception;

/**
 * The calls of a verified method would inline too many bodies, or nest too deeply.
 * Its message names the whole chain of calls, so the callers do not prefix it.
 */
final class InliningLimitExceeded extends VerificationRejected
{
}
