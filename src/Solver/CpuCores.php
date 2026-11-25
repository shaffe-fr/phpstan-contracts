<?php

declare(strict_types=1);

namespace PhpstanContracts\Solver;

use Symfony\Component\Process\Process;

/**
 * The number of CPU cores of the machine, read without a PHP extension. When it cannot be read it is 1.
 */
final class CpuCores
{
    public static function count(): int
    {
        $environment = getenv('NUMBER_OF_PROCESSORS');
        if (is_string($environment) && ctype_digit($environment) && (int) $environment > 0) {
            return (int) $environment;
        }

        if (is_readable('/proc/cpuinfo')) {
            $cores = preg_match_all('/^processor\s*:/m', (string) @file_get_contents('/proc/cpuinfo'));
            if ($cores > 0) {
                return $cores;
            }
        }

        if (in_array(PHP_OS_FAMILY, ['Darwin', 'BSD'], true)) {
            $process = new Process(['sysctl', '-n', 'hw.ncpu']);
            $process->setTimeout(5);
            try {
                $process->run();
            } catch (\Throwable) {
                return 1;
            }
            $output = trim($process->getOutput());
            if (ctype_digit($output) && (int) $output > 0) {
                return (int) $output;
            }
        }

        return 1;
    }
}
