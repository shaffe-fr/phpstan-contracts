<?php

declare(strict_types=1);

namespace PhpstanContracts\Tests\Docs;

use FilesystemIterator;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;

/**
 * The documentation shows real code and real diagnostics. These checks do not
 * need the solver: they keep the pages from drifting away from the repository.
 */
final class DocumentationTest extends TestCase
{
    /**
     * Blocks that are illustrations, or that were captured from a throwaway file
     * and cannot be checked against `examples/`. Each one is named by its first
     * meaningful line.
     */
    private const NOT_FROM_THE_EXAMPLES = [
        '// Application code: not verified.',
        'namespace App\\Billing;',
        '* @param int<0, 100000> $price',
    ];

    /**
     * @return iterable<string, array{string}>
     */
    public static function pages(): iterable
    {
        foreach (glob(__DIR__ . '/../../docs/*.md') ?: [] as $file) {
            yield 'docs/' . basename($file) => [$file];
        }
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function everyMarkdownFile(): iterable
    {
        yield from self::pages();
        yield 'README.md' => [__DIR__ . '/../../README.md'];
    }

    #[DataProvider('pages')]
    public function testEveryCodeLineQuotedFromTheExamplesExistsInThem(string $file): void
    {
        $known = self::exampleLines();
        $missing = [];

        foreach ($this->phpBlocks($file) as $block) {
            if ($this->isIllustration($block)) {
                continue;
            }

            foreach (explode("\n", $block) as $line) {
                $normalised = self::normalise($line);
                if ($normalised === '' || str_starts_with($normalised, '//')) {
                    continue;
                }
                if (!isset($known[$normalised])) {
                    $missing[] = trim($line);
                }
            }
        }

        self::assertSame([], $missing, basename($file) . ' quotes code that is in none of examples/, src/, tests/Rule and tests/Fixtures: it is either stale or an illustration that should be marked as one.');
    }

    #[DataProvider('everyMarkdownFile')]
    public function testEveryInternalLinkAndAnchorPointsSomewhere(string $file): void
    {
        $text = (string) file_get_contents($file);
        preg_match_all('/\]\(([^)\s]+)\)/', $text, $matches);

        $broken = [];
        foreach ($matches[1] as $link) {
            if (preg_match('#^(https?:|mailto:)#', $link) === 1) {
                continue;
            }

            [$path, $anchor] = array_pad(explode('#', $link, 2), 2, null);
            $target = $path === '' ? $file : dirname($file) . '/' . $path;

            if (!file_exists($target)) {
                $broken[] = $link . ' (no such file)';
                continue;
            }
            if ($anchor !== null && str_ends_with($target, '.md') && !in_array($anchor, self::anchorsOf($target), true)) {
                $broken[] = $link . ' (no such heading)';
            }
        }

        self::assertSame([], $broken, basename($file) . ' has broken links.');
    }

    public function testEveryPageIsLinkedFromTheDocumentationIndex(): void
    {
        $index = (string) file_get_contents(__DIR__ . '/../../docs/README.md');
        $unlinked = [];

        foreach (glob(__DIR__ . '/../../docs/*.md') ?: [] as $page) {
            $name = basename($page);
            if ($name !== 'README.md' && !str_contains($index, '(' . $name . ')')) {
                $unlinked[] = $name;
            }
        }

        self::assertSame([], $unlinked);
    }

    public function testEveryDiagnosticIdentifierIsDocumented(): void
    {
        $documented = (string) file_get_contents(__DIR__ . '/../../docs/writing-contracts.md');
        $sources = '';
        foreach (new RecursiveIteratorIterator(new RecursiveDirectoryIterator(__DIR__ . '/../../src', FilesystemIterator::SKIP_DOTS)) as $file) {
            $sources .= (string) file_get_contents($file->getPathname());
        }

        preg_match_all("/'(contracts\.[A-Za-z]+)'/", $sources, $literals);
        preg_match_all("/case (\w+) = '(\w+)'/", (string) file_get_contents(__DIR__ . '/../../src/Translate/ObligationKind.php'), $kinds);

        $identifiers = array_unique([...$literals[1], ...array_map(static fn (string $kind): string => 'contracts.' . $kind, $kinds[2])]);
        sort($identifiers);

        $undocumented = array_values(array_filter(
            $identifiers,
            static fn (string $identifier): bool => !str_contains($documented, '`' . $identifier . '`'),
        ));

        self::assertNotSame([], $identifiers, 'The sources should declare identifiers.');
        self::assertSame([], $undocumented, 'These diagnostic identifiers are missing from docs/writing-contracts.md.');
    }

    /**
     * @return list<string>
     */
    private function phpBlocks(string $file): array
    {
        $text = str_replace("\r\n", "\n", (string) file_get_contents($file));
        preg_match_all('/```php\n(.*?)```/s', $text, $matches);

        return $matches[1];
    }

    private function isIllustration(string $block): bool
    {
        foreach (self::NOT_FROM_THE_EXAMPLES as $marker) {
            if (str_contains($block, $marker)) {
                return true;
            }
        }

        return false;
    }

    /**
     * @return array<string, true>
     */
    private static function exampleLines(): array
    {
        $lines = [];

        // The examples are the code shown to readers. docs/testing.md quotes the rule tests, the fixtures and the package itself.
        foreach (['/../../examples', '/../Rule', '/../Fixtures', '/../../src'] as $directory) {
            $files = new RecursiveIteratorIterator(new RecursiveDirectoryIterator(__DIR__ . $directory, FilesystemIterator::SKIP_DOTS));

            foreach ($files as $file) {
                if ($file->getExtension() !== 'php') {
                    continue;
                }
                foreach (file($file->getPathname()) ?: [] as $line) {
                    $lines[self::normalise($line)] = true;
                }
            }
        }

        return $lines;
    }

    private static function normalise(string $line): string
    {
        return trim((string) preg_replace('/\s+/', ' ', $line));
    }

    /**
     * @return list<string> the anchors GitHub gives to the headings of a page
     */
    private static function anchorsOf(string $file): array
    {
        $text = (string) preg_replace('/```.*?```/s', '', str_replace("\r\n", "\n", (string) file_get_contents($file)));
        preg_match_all('/^#{1,6} (.+)$/m', $text, $headings);

        return array_map(static function (string $heading): string {
            $slug = strtolower(trim($heading));
            $slug = (string) preg_replace('/[^\p{L}\p{N}\s-]/u', '', $slug);

            return (string) preg_replace('/\s/', '-', $slug);
        }, $headings[1]);
    }
}
