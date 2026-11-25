<?php

declare(strict_types=1);

namespace PhpstanContracts\Tests\Unit\Solver;

use PhpstanContracts\Solver\SolverFailure;
use PhpstanContracts\Solver\Z3Installer;
use PHPUnit\Framework\TestCase;

/**
 * Nothing here reads the real user cache or the real environment: every location is injected and lives
 * in a temporary directory that the test removes.
 */
final class Z3InstallerTest extends TestCase
{
    private string $sandbox;

    protected function setUp(): void
    {
        $this->sandbox = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'contracts-z3-' . bin2hex(random_bytes(6));
        mkdir($this->sandbox, 0o755, true);
    }

    protected function tearDown(): void
    {
        $this->remove($this->sandbox);
    }

    public function testTheDefaultInstallDirectoryIsOutsideThePackage(): void
    {
        // Reproduces the old behaviour: the default used to be `<package>/.z3`, which lives in vendor/
        // and is wiped by every `composer update`.
        $package = dirname(__DIR__, 3);
        $legacy = Z3Installer::legacyDirectory();

        self::assertSame($package . DIRECTORY_SEPARATOR . '.z3', $legacy);

        $default = Z3Installer::cacheDirectory(['HOME' => $this->sandbox, 'LOCALAPPDATA' => $this->sandbox], PHP_OS_FAMILY);
        self::assertNotNull($default);
        self::assertStringStartsNotWith($package, $default);
    }

    public function testWindowsUsesLocalAppData(): void
    {
        $base = $this->sandbox . DIRECTORY_SEPARATOR . 'local';

        self::assertSame(
            $base . DIRECTORY_SEPARATOR . 'phpstan-contracts' . DIRECTORY_SEPARATOR . 'z3',
            Z3Installer::cacheDirectory(['LOCALAPPDATA' => $base . DIRECTORY_SEPARATOR, 'HOME' => '/ignored'], 'Windows'),
        );
        self::assertNull(Z3Installer::cacheDirectory(['HOME' => '/home/someone'], 'Windows'));
        self::assertNull(Z3Installer::cacheDirectory(['LOCALAPPDATA' => ''], 'Windows'));
    }

    public function testXdgCacheHomeWinsWhenItIsAbsolute(): void
    {
        $xdg = $this->sandbox . DIRECTORY_SEPARATOR . 'xdg';

        foreach (['Linux', 'Darwin'] as $family) {
            self::assertSame(
                $xdg . DIRECTORY_SEPARATOR . 'phpstan-contracts' . DIRECTORY_SEPARATOR . 'z3',
                Z3Installer::cacheDirectory(['XDG_CACHE_HOME' => $xdg, 'HOME' => '/home/someone'], $family),
            );
        }
    }

    public function testWithoutXdgTheCacheIsUnderTheHomeDotCache(): void
    {
        $home = $this->sandbox . DIRECTORY_SEPARATOR . 'home';
        $expected = $home . DIRECTORY_SEPARATOR . '.cache' . DIRECTORY_SEPARATOR . 'phpstan-contracts' . DIRECTORY_SEPARATOR . 'z3';

        foreach (['Linux', 'Darwin'] as $family) {
            self::assertSame($expected, Z3Installer::cacheDirectory(['HOME' => $home], $family));
            // A relative XDG_CACHE_HOME is invalid per the XDG specification and is ignored.
            self::assertSame($expected, Z3Installer::cacheDirectory(['HOME' => $home, 'XDG_CACHE_HOME' => 'relative/dir'], $family));
            self::assertSame($expected, Z3Installer::cacheDirectory(['HOME' => $home . '/', 'XDG_CACHE_HOME' => ''], $family));
        }
    }

    public function testNoCacheLocationIsInventedWhenTheEnvironmentHasNone(): void
    {
        self::assertNull(Z3Installer::cacheDirectory([], 'Linux'));
        self::assertNull(Z3Installer::cacheDirectory(['HOME' => 'relative'], 'Linux'));
        self::assertNull(Z3Installer::cacheDirectory(['HOME' => ''], 'Darwin'));
    }

    public function testTheBinaryIsPlacedInAVersionDirectoryOfTheGivenDirectory(): void
    {
        $binary = (new Z3Installer($this->sandbox))->binaryPath();

        self::assertSame(
            $this->sandbox . DIRECTORY_SEPARATOR . Z3Installer::VERSION . DIRECTORY_SEPARATOR . (PHP_OS_FAMILY === 'Windows' ? 'z3.exe' : 'z3'),
            $binary,
        );
    }

    public function testTheCacheIsSearchedBeforeTheLegacyDirectory(): void
    {
        $cache = $this->sandbox . DIRECTORY_SEPARATOR . 'cache';
        $legacy = $this->sandbox . DIRECTORY_SEPARATOR . 'legacy';

        self::assertNull(Z3Installer::installedBinary($cache, $legacy));

        $legacyBinary = $this->fakeBinary($legacy);
        self::assertSame($legacyBinary, Z3Installer::installedBinary($cache, $legacy), 'an existing legacy install keeps working');

        $cacheBinary = $this->fakeBinary($cache);
        self::assertSame($cacheBinary, Z3Installer::installedBinary($cache, $legacy), 'the cache comes first');
    }

    public function testAnUnknownCacheLocationStillFindsTheLegacyInstall(): void
    {
        $legacy = $this->sandbox . DIRECTORY_SEPARATOR . 'legacy';
        $legacyBinary = $this->fakeBinary($legacy);

        self::assertSame($legacyBinary, Z3Installer::installedBinary(null, $legacy, []));
        self::assertNull(Z3Installer::installedBinary(null, $this->sandbox . DIRECTORY_SEPARATOR . 'none', []));
    }

    public function testInstallingWithoutAnyKnownLocationFailsInsteadOfGuessing(): void
    {
        $this->expectException(SolverFailure::class);
        $this->expectExceptionMessage('--dir');

        Z3Installer::defaultDirectory([]);
    }

    private function fakeBinary(string $root): string
    {
        $path = (new Z3Installer($root))->binaryPath();
        mkdir(dirname($path), 0o755, true);
        file_put_contents($path, 'not a real binary');

        return $path;
    }

    private function remove(string $path): void
    {
        if (is_file($path) || is_link($path)) {
            unlink($path);

            return;
        }
        foreach (scandir($path) ?: [] as $entry) {
            if ($entry !== '.' && $entry !== '..') {
                $this->remove($path . DIRECTORY_SEPARATOR . $entry);
            }
        }
        rmdir($path);
    }
}
