<?php

declare(strict_types=1);

namespace PhpstanContracts\Tests\Cache;

use FilesystemIterator;
use PhpstanContracts\Tests\Support\RequiresZ3;
use PHPUnit\Framework\TestCase;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use SplFileInfo;
use Symfony\Component\Process\Process;

/**
 * Runs PHPStan on a copy of tests/CacheProject twice, then edits, one at a time, files
 * that A's proofs read but PHPStan does not see A depend on, and checks after each edit
 * that the next run re-analyses A: its result cache must not keep a proof that the edit
 * broke. The edits add up, each one breaking another method of A.
 *
 * One row per way a proof can read another file, PHPDoc types included: what PHPStan
 * resolves a PHPDoc type to often names nothing it was computed from. A row whose
 * construct the proof does not read (the translation drops it) checks instead that A is
 * the one file re-analysed after an edit of lib/, which only A uses.
 *
 * The project runs on two workers, one file per job, so the files are spread over both,
 * as in a parallel run of a real project.
 */
final class ResultCacheTest extends TestCase
{
    use RequiresZ3;

    private const REANALYSED = 'reanalysed';

    private string $project = '';

    protected function tearDown(): void
    {
        if ($this->project !== '' && is_dir($this->project)) {
            self::remove($this->project);
        }
    }

    /**
     * The file to edit, the text to replace in it (null to create the file), the new text,
     * and either what A reports afterwards ("identifier: message fragment") or
     * {@see self::REANALYSED}.
     *
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
        yield 'value-of<> an enum of another file' => [
            'src/Priority.php',
            'case Only = 3;',
            'case Only = 4;',
            'contracts.ensuresViolated: #[Ensures(\'$result <= 3\')]',
        ];
        yield 'an alias of the typeAliases parameter naming a class constant' => [
            'src/Ceiling.php',
            'public const MAX = 10;',
            'public const MAX = 11;',
            'contracts.ensuresViolated: Counterexample: $ceiled = 11',
        ];
        yield 'key-of<> a class constant' => [
            'src/Keys.php',
            '[3 =>',
            '[4 =>',
            'contracts.ensuresViolated: Counterexample: $k = 4',
        ];
        yield 'an alias of @phpstan-type imported with @phpstan-import-type' => [
            'src/Shares.php',
            '@phpstan-type Share int<0, 10>',
            '@phpstan-type Share int<0, 11>',
            'contracts.ensuresViolated: Counterexample: $s = 11',
        ];
        yield 'a constant of the class a @template is bound to' => [
            'src/Tiers.php',
            'public const TOP = 10;',
            'public const TOP = 11;',
            'contracts.ensuresViolated: $tiered = 11',
        ];
        yield 'a class constant declared by the parent' => [
            'src/RankBase.php',
            'public const TOP = 10;',
            'public const TOP = 11;',
            'contracts.ensuresViolated: Counterexample: $inherited = 11',
        ];
        yield 'a class constant declared by a trait' => [
            'src/RankTrait.php',
            'public const LOW = 10;',
            'public const LOW = 11;',
            'contracts.ensuresViolated: Counterexample: $used = 11',
        ];
        yield 'a class constant whose initializer reads a constant of another file' => [
            'src/ChainEnd.php',
            'public const MAX = 10;',
            'public const MAX = 11;',
            'contracts.ensuresViolated: Counterexample: $chained = 11',
        ];
        yield 'self:: constant whose initializer reads a constant of another file' => [
            'src/OwnEnd.php',
            'public const MAX = 10;',
            'public const MAX = 11;',
            'contracts.ensuresViolated: Counterexample: $own = 11',
        ];
        yield 'value-of<> an enum whose case reads a constant of another file' => [
            'src/LevelEnd.php',
            'public const TOP = 3;',
            'public const TOP = 4;',
            'contracts.ensuresViolated: Counterexample: $level = 4',
        ];
        yield 'the body of a called method of another file' => [
            'src/B.php',
            'return $value;',
            'return $value === 0 ? 1 : $value;',
            'contracts.ensuresViolated: Counterexample: $bodied = 0',
        ];
        yield 'a private constant read by the body of a called method' => [
            'src/B.php',
            'private const STEP = 7;',
            'private const STEP = 8;',
            'contracts.ensuresViolated: #[Ensures(\'$result === 7\')]',
        ];
        yield 'the constructor of a class built with new gains a statement' => [
            'src/R.php',
            "public int \$amount)\n    {\n    }",
            "public int \$amount)\n    {\n        assert(\$amount >= 0);\n    }",
            'contracts.unsupported: Cannot instantiate R: its constructor contains logic, which is not verified.',
        ];
        yield 'the parent of a class built with new, in a third file, gains a private destructor' => [
            'src/StampBase.php',
            'public const KIND = 1;',
            "public const KIND = 1;\n\n    private function __destruct()\n    {\n    }",
            'contracts.unsupported: Cannot instantiate Stamp: its destructor runs code that is not verified.',
        ];
        yield 'a trait used by a class built with new gains a destructor' => [
            'src/Tags.php',
            'public const KIND = 1;',
            "public const KIND = 1;\n\n    public function __destruct()\n    {\n    }",
            'contracts.unsupported: Cannot instantiate Tagged: its destructor runs code that is not verified.',
        ];
        yield 'the body of a called method in a scanned directory, outside the analysed paths' => [
            'lib/Tax.php',
            'return $value;',
            'return $value === 0 ? 1 : $value;',
            'contracts.ensuresViolated: Counterexample: $taxed = 0',
        ];
        yield '@extends of a data class naming a constant (not read by the proof)' => [
            'lib/LibGrades.php',
            'public const MAX = 10;',
            'public const MAX = 11;',
            self::REANALYSED,
        ];
        yield 'a conditional return type naming a constant (not read by the proof)' => [
            'lib/LibCap.php',
            'public const MAX = 10;',
            'public const MAX = 11;',
            self::REANALYSED,
        ];
        yield '@mixin (not read by the proof)' => [
            'lib/LibExtra.php',
            'public const VERSION = 1;',
            'public const VERSION = 2;',
            self::REANALYSED,
        ];
        yield '@method (not read by the proof)' => [
            'lib/LibMagic.php',
            'public const VERSION = 1;',
            'public const VERSION = 2;',
            self::REANALYSED,
        ];
        yield 'class-string<> (not read by the proof)' => [
            'lib/LibClass.php',
            'public const VERSION = 1;',
            'public const VERSION = 2;',
            self::REANALYSED,
        ];
        yield 'an enum case as a PHPDoc type' => [
            'lib/LibTint.php',
            "    case Blue;\n",
            "    case Blue;\n    case Green;\n",
            self::REANALYSED,
        ];
    }

    public function testEachEditReanalysesTheFilesWhoseProofsReadIt(): void
    {
        $z3 = $this->requireZ3();
        $this->project = $this->copyProject($z3);

        self::assertSame([], $this->analyse(false)['errors'], 'cold run');
        $warm = $this->analyse(true);
        self::assertSame([], $warm['errors'], 'warm run');
        self::assertStringContainsString('Result cache restored', $warm['stderr'], 'the warm run uses the result cache');

        // Every row is run, so that one report lists each edit that left a stale proof.
        $failures = [];
        foreach (self::edits() as $label => [$file, $search, $replace, $expected]) {
            $path = $this->project . '/' . $file;
            if ($search === null) {
                file_put_contents($path, $replace);
            } else {
                // Checked out with the line endings of the platform: edit with \n, write back with the file's own.
                $original = (string) file_get_contents($path);
                $ending = str_contains($original, "\r\n") ? "\r\n" : "\n";
                $contents = str_replace("\r\n", "\n", $original);
                self::assertStringContainsString($search, $contents, $label);
                file_put_contents($path, str_replace("\n", $ending, str_replace($search, $replace, $contents)));
            }

            $run = $this->analyse(true);
            self::assertStringContainsString('Result cache restored', $run['stderr'], $label . ': the run uses the result cache');
            $outside = array_diff(array_keys($run['errors']), ['src/A.php']);
            self::assertSame([], $outside, $label . ': only A reports errors: ' . json_encode($run['errors']));

            if ($expected === self::REANALYSED) {
                if (!str_contains($run['stderr'], 'Result cache restored. 1 file will be reanalysed.')) {
                    $failures[] = sprintf('%s: A is not re-analysed (%s)', $label, self::restored($run['stderr']));
                }
                continue;
            }
            [$identifier, $fragment] = explode(': ', $expected, 2);
            $matching = array_filter(
                $run['errors']['src/A.php'] ?? [],
                static fn (array $error): bool => $error['identifier'] === $identifier && str_contains($error['message'], $fragment),
            );
            if ($matching === []) {
                $failures[] = sprintf('%s: A does not report %s (%s)', $label, $expected, self::restored($run['stderr']));
            }
        }

        self::assertSame([], $failures, 'stale proofs');
    }

    private static function restored(string $stderr): string
    {
        return preg_match('(Result cache restored\. [^\r\n]*)', $stderr, $match) === 1 ? $match[0] : 'no result cache line';
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
