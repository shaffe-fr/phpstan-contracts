<?php

declare(strict_types=1);

namespace PhpstanContracts\Tests\Support;

use PhpstanContracts\Solver\Z3Installer;
use Symfony\Component\Process\ExecutableFinder;

/**
 * Tests that run the solver skip themselves when Z3 is missing, unless the
 * environment says it must be there (as CI does), in which case they fail.
 */
trait RequiresZ3
{
    protected static function locateZ3(): ?string
    {
        return Z3Installer::installedBinary() ?? (new ExecutableFinder())->find('z3');
    }

    protected function requireZ3(): string
    {
        $binary = self::locateZ3();
        if ($binary !== null) {
            return $binary;
        }

        if (getenv('CONTRACTS_REQUIRE_Z3') === '1') {
            self::fail('Z3 is required (CONTRACTS_REQUIRE_Z3=1) but was not found.');
        }

        self::markTestSkipped('Z3 is not installed: run vendor/bin/phpstan-contracts-install-z3.');
    }
}
