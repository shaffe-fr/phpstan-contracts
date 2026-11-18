<?php

declare(strict_types=1);

namespace PhpstanContracts\Solver;

use Symfony\Component\Process\Process;
use ZipArchive;

/**
 * Downloads the official Z3 release for the current platform, so that no Python or system package is needed.
 *
 * The version and the SHA-256 of every archive are pinned in this file: what is downloaded is only trusted
 * if it matches a hash that shipped with the package, whatever the server answers.
 */
final class Z3Installer
{
    public const VERSION = '5.4.1';

    private const DOWNLOAD_BASE = 'https://github.com/Z3Prover/z3/releases/download/z3-' . self::VERSION . '/';

    /**
     * Files taken from `bin/` in the archive: the executable and, on Windows, the libraries it loads.
     */
    private const EXTRACTED_FILES = '#^[^/]+/bin/(z3|z3\.exe|libz3\.dll|msvcp140[a-z_0-9]*\.dll|vcruntime140[a-z_0-9]*\.dll|vcomp140\.dll)$#';

    /**
     * @var array<string, array{file: string, sha256: string}>
     */
    private const ARCHIVES = [
        'windows-x64' => [
            'file' => 'z3-5.4.1-x64-win.zip',
            'sha256' => 'f16e991e1c883b0cee30c78eb445343e1a3f49c142aa7dead7d6c35f9cc44934',
        ],
        'windows-arm64' => [
            'file' => 'z3-5.4.1-arm64-win.zip',
            'sha256' => '1159ecd4cb008838471d816f09bdf69654a1908b47a7279fca2fc8dadbb81e0f',
        ],
        'linux-x64' => [
            'file' => 'z3-5.4.1-x64-glibc-2.39.zip',
            'sha256' => 'b548bac48ef2452b4e09ea2945cf4b011d5f43fcf39e4799df268ee7e0769d85',
        ],
        'linux-arm64' => [
            'file' => 'z3-5.4.1-arm64-glibc-2.38.zip',
            'sha256' => '9e8f781f15a423ede5bd491b665966dbb607d9f9c4f9f9b502116f271d1b050f',
        ],
        'darwin-x64' => [
            'file' => 'z3-5.4.1-x64-osx-13.3.zip',
            'sha256' => '79e93b0564b82bf2c1b355df130bab543b1ff88d597c985632770fea05f1b249',
        ],
        'darwin-arm64' => [
            'file' => 'z3-5.4.1-arm64-osx-13.3.zip',
            'sha256' => 'a4a878121e81dfc297d027dd0b51dc4f08feb4296d6ecace42bfbc87a29d6d58',
        ],
    ];

    public function __construct(
        private readonly ?string $directory = null,
    ) {
    }

    public static function defaultDirectory(): string
    {
        return dirname(__DIR__, 2) . DIRECTORY_SEPARATOR . '.z3';
    }

    /**
     * Path of the binary installed in the default directory, or null when there is none.
     */
    public static function installedBinary(): ?string
    {
        $path = (new self())->binaryPath();

        return is_file($path) ? $path : null;
    }

    public function binaryPath(): string
    {
        return $this->versionDirectory() . DIRECTORY_SEPARATOR . (PHP_OS_FAMILY === 'Windows' ? 'z3.exe' : 'z3');
    }

    /**
     * @param callable(string): void $log
     *
     * @return string path of the installed executable
     */
    public function install(callable $log, bool $force = false): string
    {
        $binary = $this->binaryPath();
        if (is_file($binary) && !$force) {
            $log('Z3 ' . self::VERSION . ' is already installed.');

            return $binary;
        }

        if (!class_exists(ZipArchive::class)) {
            throw new SolverFailure('The PHP zip extension is required to unpack Z3. Enable ext-zip, or install Z3 with your package manager.');
        }

        $archive = self::ARCHIVES[$this->platform()];
        $url = self::DOWNLOAD_BASE . $archive['file'];

        $parent = dirname($this->versionDirectory());
        $this->createDirectory($parent);
        $workDirectory = $parent . DIRECTORY_SEPARATOR . '.tmp-' . bin2hex(random_bytes(6));
        $this->createDirectory($workDirectory);

        try {
            $zipPath = $workDirectory . DIRECTORY_SEPARATOR . $archive['file'];
            $log('Downloading ' . $url);
            $this->download($url, $zipPath);

            $log('Verifying SHA-256');
            $actual = hash_file('sha256', $zipPath);
            if ($actual === false || !hash_equals($archive['sha256'], $actual)) {
                throw new SolverFailure(sprintf(
                    'Checksum mismatch for %s: expected %s, got %s. Nothing was installed.',
                    $archive['file'],
                    $archive['sha256'],
                    $actual === false ? 'unreadable file' : $actual,
                ));
            }

            $stagingDirectory = $workDirectory . DIRECTORY_SEPARATOR . 'out';
            $this->createDirectory($stagingDirectory);
            $this->extract($zipPath, $stagingDirectory);

            $staged = $stagingDirectory . DIRECTORY_SEPARATOR . basename($binary);
            if (!is_file($staged)) {
                throw new SolverFailure('The archive does not contain ' . basename($binary) . '.');
            }

            chmod($staged, 0o755);
            $this->smokeTest($staged);

            $this->removeDirectory($this->versionDirectory());
            if (!rename($stagingDirectory, $this->versionDirectory())) {
                throw new SolverFailure('Could not move Z3 to ' . $this->versionDirectory() . '.');
            }
        } finally {
            $this->removeDirectory($workDirectory);
        }

        $log('Installed Z3 ' . self::VERSION . ' in ' . $this->versionDirectory());

        return $binary;
    }

    private function versionDirectory(): string
    {
        return rtrim($this->directory ?? self::defaultDirectory(), '/\\') . DIRECTORY_SEPARATOR . self::VERSION;
    }

    private function platform(): string
    {
        $os = match (PHP_OS_FAMILY) {
            'Windows' => 'windows',
            'Linux' => 'linux',
            'Darwin' => 'darwin',
            default => throw new SolverFailure(sprintf('No official Z3 build for %s. Install Z3 with your package manager.', PHP_OS_FAMILY)),
        };

        $architecture = match (strtolower(php_uname('m'))) {
            'x86_64', 'amd64' => 'x64',
            'arm64', 'aarch64' => 'arm64',
            default => throw new SolverFailure(sprintf('No official Z3 build for the %s architecture. Install Z3 with your package manager.', php_uname('m'))),
        };

        return $os . '-' . $architecture;
    }

    private function download(string $url, string $destination): void
    {
        if (!str_starts_with($url, 'https://')) {
            throw new SolverFailure('Refusing to download over an insecure connection.');
        }

        $context = stream_context_create([
            'http' => [
                'follow_location' => 1,
                'max_redirects' => 5,
                'user_agent' => 'phpstan-contracts',
                'timeout' => 120,
            ],
            'ssl' => [
                'verify_peer' => true,
                'verify_peer_name' => true,
            ],
        ]);

        $source = @fopen($url, 'rb', false, $context);
        $target = fopen($destination, 'wb');
        if ($source === false || $target === false) {
            throw new SolverFailure('Could not download ' . $url . '. Check your network, or that allow_url_fopen and openssl are enabled.');
        }

        try {
            if (stream_copy_to_stream($source, $target) === false) {
                throw new SolverFailure('The download of ' . $url . ' was interrupted.');
            }
        } finally {
            fclose($source);
            fclose($target);
        }
    }

    private function extract(string $zipPath, string $destination): void
    {
        $zip = new ZipArchive();
        if ($zip->open($zipPath) !== true) {
            throw new SolverFailure('The downloaded file is not a valid zip archive.');
        }

        try {
            for ($index = 0; $index < $zip->numFiles; ++$index) {
                $name = $zip->getNameIndex($index);
                if ($name === false || preg_match(self::EXTRACTED_FILES, $name) !== 1) {
                    continue;
                }

                // Only the base name is used, so an entry can never write outside the destination.
                $input = $zip->getStream($name);
                $output = fopen($destination . DIRECTORY_SEPARATOR . basename($name), 'wb');
                if ($input === false || $output === false) {
                    throw new SolverFailure('Could not extract ' . $name . '.');
                }

                stream_copy_to_stream($input, $output);
                fclose($input);
                fclose($output);
            }
        } finally {
            $zip->close();
        }
    }

    private function smokeTest(string $binary): void
    {
        $process = new Process([$binary, '--version']);
        $process->setTimeout(30);
        $process->run();

        if (!$process->isSuccessful() || !str_contains($process->getOutput(), self::VERSION)) {
            throw new SolverFailure(sprintf(
                'The downloaded Z3 does not run on this machine (%s). On Linux the official build needs a recent glibc; install Z3 with your package manager instead.',
                trim($process->getErrorOutput() . $process->getOutput()) ?: 'no output',
            ));
        }
    }

    private function createDirectory(string $path): void
    {
        if (!is_dir($path) && !mkdir($path, 0o755, true) && !is_dir($path)) {
            throw new SolverFailure('Could not create the directory ' . $path . '.');
        }
    }

    private function removeDirectory(string $path): void
    {
        if (!is_dir($path)) {
            return;
        }

        foreach (scandir($path) ?: [] as $entry) {
            if ($entry === '.' || $entry === '..') {
                continue;
            }

            $child = $path . DIRECTORY_SEPARATOR . $entry;
            if (is_dir($child) && !is_link($child)) {
                $this->removeDirectory($child);
            } else {
                @unlink($child);
            }
        }

        @rmdir($path);
    }
}
