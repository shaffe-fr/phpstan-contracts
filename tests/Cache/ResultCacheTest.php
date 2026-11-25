<?php

declare(strict_types=1);

namespace PhpstanContracts\Tests\Cache;

use FilesystemIterator;
use PhpstanContracts\Tests\Support\RequiresZ3;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use SplFileInfo;
use Symfony\Component\Process\Process;

/**
 * Runs PHPStan on a copy of tests/CacheProject twice, then edits a file that A's proofs
 * read but PHPStan does not see A depend on, and checks that the third run re-analyses
 * A: its result cache must not keep the proof that the edit broke.
 *
 * The project runs on two workers, one file per job, so the files are spread over both,
 * as in a parallel run of a real project.
 */
final class ResultCacheTest extends TestCase
{
    use RequiresZ3;

    private string $project = '';

    protected function tearDown(): void
    {
        if ($this->project !== '' && is_dir($this->project)) {
            self::remove($this->project);
        }
    }

    /**
     * @return iterable<string, array{string, string|null, string, string}>
     */
    public static function edits(): iterable
    {
        yield '#[\Deprecated] on an enum case of another file' => [
            'src/E.php',
            "    case Old;",
            "    #[\\Deprecated]\n    case Old;",
            'contracts.unsupported: Enum case CacheProject\E::Old is deprecated',
        ];
        yield 'a class constant named only in a contract string' => [
            'src/Limits.php',
            'public const MAX = 10;',
            'public const MAX = 11;',
            'contracts.ensuresViolated: #[Ensures(\'$result === Limits::MAX\')]',
        ];
        yield 'a short name in a contract string gains a namespaced twin' => [
            'src/Rate.php',
            null,
            "<?php\n\ndeclare(strict_types=1);\n\nnamespace CacheProject;\n\nfinal class Rate\n{\n    public const BASE = 6;\n}\n",
            'contracts.ensuresViolated: #[Ensures(\'$result === Rate::BASE\')]',
        ];
        yield 'a class constant in the PHPDoc range of a parameter' => [
            'src/Caps.php',
            'public const MAX = 10;',
            'public const MAX = 11;',
            'contracts.ensuresViolated: #[Ensures(\'$result <= 10\')]',
        ];
        yield 'a class constant in the PHPDoc range of a data class property of a third file' => [
            'src/Amounts.php',
            'public const MAX = 10;',
            'public const MAX = 11;',
            'contracts.ensuresViolated: #[Ensures(\'$result->amount <= 10\')]',
        ];
    }

    /**
     * @param string|null $search the text to replace in $file, or null to create $file with $replace
     */
    #[DataProvider('edits')]
    public function testAnEditReanalysesTheFilesWhoseProofsReadIt(string $file, ?string $search, string $replace, string $expected): void
    {
        $z3 = $this->requireZ3();
        $this->project = $this->copyProject($z3);

        self::assertSame([], $this->analyse(false)['errors'], 'cold run');
        $warm = $this->analyse(true);
        self::assertSame([], $warm['errors'], 'warm run');
        self::assertStringContainsString('Result cache restored', $warm['stderr'], 'the warm run uses the result cache');

        $path = $this->project . '/' . $file;
        if ($search === null) {
            file_put_contents($path, $replace);
        } else {
            $contents = (string) file_get_contents($path);
            self::assertStringContainsString($search, $contents);
            file_put_contents($path, str_replace($search, $replace, $contents));
        }

        [$identifier, $fragment] = explode(': ', $expected, 2);
        $errors = $this->analyse(true)['errors'];
        self::assertArrayHasKey('src/A.php', $errors, 'A is re-analysed after the edit: ' . json_encode($errors));
        $matching = array_filter(
            $errors['src/A.php'],
            static fn (array $error): bool => $error['identifier'] === $identifier && str_contains($error['message'], $fragment),
        );
        self::assertNotEmpty($matching, sprintf('A reports %s: %s', $expected, json_encode($errors['src/A.php'])));
    }

    private function copyProject(string $z3): string
    {
        $temp = realpath(sys_get_temp_dir());
        self::assertIsString($temp);
        $project = $temp . DIRECTORY_SEPARATOR . 'phpstan-contracts-cache-' . bin2hex(random_bytes(6));
        $source = dirname(__DIR__) . '/CacheProject';
        $files = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($source, FilesystemIterator::SKIP_DOTS), RecursiveIteratorIterator::SELF_FIRST);
        mkdir($project);
        foreach ($files as $item) {
            assert($item instanceof SplFileInfo);
            $target = $project . substr($item->getPathname(), strlen($source));
            $item->isDir() ? mkdir($target) : copy($item->getPathname(), $target);
        }

        $root = dirname(__DIR__, 2);
        $quote = static fn (string $value): string => "'" . str_replace("'", "''", str_replace('\\', '/', $value)) . "'";
        file_put_contents($project . '/phpstan.neon', strtr((string) file_get_contents($project . '/phpstan.neon.template'), [
            '{extension}' => $quote($root . '/extension.neon'),
            '{autoload}' => $quote($root . '/vendor/autoload.php'),
            '{z3}' => $quote($z3),
        ]));

        return $project;
    }

    /**
     * @return array{errors: array<string, list<array{message: string, identifier: string}>>, stderr: string}
     */
    private function analyse(bool $verbose): array
    {
        $command = [PHP_BINARY, dirname(__DIR__, 2) . '/vendor/bin/phpstan', 'analyse', '-c', 'phpstan.neon', '--error-format=json', '--no-progress'];
        if ($verbose) {
            $command[] = '-vv';
        }
        $process = new Process($command, $this->project, timeout: 600);
        $process->run();

        $report = json_decode($process->getOutput(), true);
        self::assertIsArray($report, sprintf("PHPStan did not print a report (exit %s):\n%s\n%s", $process->getExitCode(), $process->getOutput(), $process->getErrorOutput()));
        self::assertSame([], $report['errors'] ?? [], 'PHPStan reports no general error');

        $errors = [];
        foreach ($report['files'] ?? [] as $file => $details) {
            $relative = str_replace('\\', '/', substr((string) $file, strlen($this->project) + 1));
            foreach ($details['messages'] as $message) {
                $errors[$relative][] = ['message' => (string) $message['message'], 'identifier' => (string) ($message['identifier'] ?? '')];
            }
        }

        return ['errors' => $errors, 'stderr' => $process->getErrorOutput()];
    }

    private static function remove(string $directory): void
    {
        $items = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($directory, FilesystemIterator::SKIP_DOTS), RecursiveIteratorIterator::CHILD_FIRST);
        foreach ($items as $item) {
            assert($item instanceof SplFileInfo);
            $item->isDir() && !$item->isLink() ? rmdir($item->getPathname()) : unlink($item->getPathname());
        }
        rmdir($directory);
    }
}
