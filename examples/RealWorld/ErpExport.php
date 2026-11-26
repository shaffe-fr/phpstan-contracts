<?php

declare(strict_types=1);

namespace PhpstanContracts\Examples\RealWorld;

use PhpstanContracts\Attribute\Ensures;
use PhpstanContracts\Attribute\Throws;

/**
 * An export line that calls the guard of ErpCode.php. The guard throws, so this method
 * must declare when it does: the proof checks that the declaration matches the guard.
 */
final class ErpExport
{
    /**
     * The line sent to the ERP for a web customer.
     */
    #[Throws(\InvalidArgumentException::class, when: '$webId < 0 || $webId >= 1000000')]
    #[Ensures('str_starts_with($result, "ERP-")')]
    public static function exportLine(int $webId): string
    {
        $code = ErpCode::from($webId);

        return 'ERP-' . $code;
    }

    /**
     * The declared range forgets the upper bound of the guard, so the export can throw
     * for ids that its declaration says are fine.
     */
    #[Throws(\InvalidArgumentException::class, when: '$webId < 0')]
    public static function exportLineBroken(int $webId): string
    {
        $code = ErpCode::from($webId);

        return 'ERP-' . $code;
    }
}
