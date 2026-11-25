<?php

declare(strict_types=1);

namespace PhpstanContracts\Tests\Unit\Solver;

use PhpstanContracts\Solver\ProofCache;
use PhpstanContracts\Solver\SolverResult;
use PhpstanContracts\Solver\Verdict;
use PHPUnit\Framework\TestCase;

final class ProofCacheTest extends TestCase
{
    private string $directory;

    protected function setUp(): void
    {
        $this->directory = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'contracts-cache-test-' . bin2hex(random_bytes(6));
    }

    protected function tearDown(): void
    {
        foreach (glob($this->directory . DIRECTORY_SEPARATOR . '*') ?: [] as $file) {
            unlink($file);
        }
        if (is_dir($this->directory)) {
            rmdir($this->directory);
        }
    }

    public function testSettledResultsAreReadBackExactly(): void
    {
        $cache = new ProofCache($this->directory);
        $results = [
            new SolverResult(Verdict::Unsat),
            new SolverResult(Verdict::Sat, ['x' => '7', 'y' => ['-', '3'], 'f' => ['_', 'NaN', '11', '53']]),
        ];

        $cache->put('abc', $results);

        self::assertEquals($results, $cache->get('abc', 2));
    }

    public function testAnEntryThatIsMissingIsAMiss(): void
    {
        self::assertNull((new ProofCache($this->directory))->get('abc', 1));
    }

    public function testAScriptHoldingAnUnknownIsNeverStored(): void
    {
        $cache = new ProofCache($this->directory);

        $cache->put('abc', [new SolverResult(Verdict::Unsat), new SolverResult(Verdict::Unknown)]);

        self::assertNull($cache->get('abc', 2));
        self::assertFalse(is_dir($this->directory) && glob($this->directory . DIRECTORY_SEPARATOR . '*.json') !== []);
    }

    public function testAnEntryWithAnotherNumberOfChecksIsAMiss(): void
    {
        $cache = new ProofCache($this->directory);
        $cache->put('abc', [new SolverResult(Verdict::Unsat)]);

        self::assertNull($cache->get('abc', 2));
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function damagedEntries(): iterable
    {
        yield 'not json' => ['{"format": 1, "resu'];
        yield 'another format' => ['{"format":2,"results":[{"verdict":"unsat","values":[]}]}'];
        yield 'unknown verdict' => ['{"format":1,"results":[{"verdict":"unknown","values":[]}]}'];
        yield 'invented verdict' => ['{"format":1,"results":[{"verdict":"maybe","values":[]}]}'];
        yield 'model on an unsat' => ['{"format":1,"results":[{"verdict":"unsat","values":{"x":"1"}}]}'];
        yield 'value of a wrong type' => ['{"format":1,"results":[{"verdict":"sat","values":{"x":1}}]}'];
        yield 'no results' => ['{"format":1}'];
    }

    #[\PHPUnit\Framework\Attributes\DataProvider('damagedEntries')]
    public function testADamagedEntryIsAMissAndNeverAnAnswer(string $content): void
    {
        mkdir($this->directory, 0777, true);
        file_put_contents($this->directory . DIRECTORY_SEPARATOR . 'abc.json', $content);

        self::assertNull((new ProofCache($this->directory))->get('abc', 1));
    }

    public function testADisabledCacheStoresNothingAndFindsNothing(): void
    {
        $cache = new ProofCache(null);

        $cache->put('abc', [new SolverResult(Verdict::Unsat)]);

        self::assertFalse($cache->isEnabled());
        self::assertNull($cache->get('abc', 1));
    }

    public function testTheKeyDependsOnTheScriptAndOnTheSolverBuild(): void
    {
        $cache = new ProofCache($this->directory);
        $first = tempnam(sys_get_temp_dir(), 'z3');
        $second = tempnam(sys_get_temp_dir(), 'z3');
        self::assertNotFalse($first);
        self::assertNotFalse($second);
        file_put_contents($second, 'a different build');

        try {
            self::assertSame($cache->key($first, '(check-sat)'), $cache->key($first, '(check-sat)'));
            self::assertNotSame($cache->key($first, '(check-sat)'), $cache->key($first, '(check-sat) '));
            self::assertNotSame($cache->key($first, '(check-sat)'), $cache->key($second, '(check-sat)'));
        } finally {
            unlink($first);
            unlink($second);
        }
    }

    public function testTheUndecidedKeyDependsOnEverythingThatDecidesTheOutcome(): void
    {
        $cache = new ProofCache($this->directory);
        $first = tempnam(sys_get_temp_dir(), 'z3');
        $second = tempnam(sys_get_temp_dir(), 'z3');
        self::assertNotFalse($first);
        self::assertNotFalse($second);
        file_put_contents($second, 'a different build');
        try {
            $key = $cache->undecidedKey($first, '(check-sat)', 1000, 60000);

            self::assertSame($key, $cache->undecidedKey($first, '(check-sat)', 1000, 60000));
            self::assertNotSame($key, $cache->undecidedKey($first, '(check-sat) ', 1000, 60000), 'script');
            self::assertNotSame($key, $cache->undecidedKey($first, '(check-sat)', 1001, 60000), 'timeout');
            self::assertNotSame($key, $cache->undecidedKey($first, '(check-sat)', 1000, 60001), 'method budget');
            self::assertNotSame($key, $cache->undecidedKey($second, '(check-sat)', 1000, 60000), 'solver build');
            self::assertNotSame($key, $cache->key($first, '(check-sat)'), 'a proof and a mark of the same script never share a key');
            $identity = realpath($first) . "\0" . filesize($first) . "\0" . filemtime($first);
            self::assertSame(
                hash('sha256', implode("\0", ['undecided', 1, ProofCache::STRATEGY_VERSION, 1000, 60000, $identity, '(check-sat)'])),
                $key,
                'the strategy version and the format are part of the key',
            );
            self::assertNotSame(
                hash('sha256', implode("\0", ['undecided', 1, ProofCache::STRATEGY_VERSION + 1, 1000, 60000, $identity, '(check-sat)'])),
                $key,
            );
        } finally {
            unlink($first);
            unlink($second);
        }
    }

    public function testAnUndecidedMarkIsReadBackAndIsNotAProof(): void
    {
        $cache = new ProofCache($this->directory);

        self::assertFalse($cache->isUndecided('abc'));
        $cache->markUndecided('abc');

        self::assertTrue($cache->isUndecided('abc'));
        self::assertTrue((new ProofCache($this->directory))->isUndecided('abc'));
        self::assertFalse($cache->isUndecided('abd'));
        self::assertNull($cache->get('abc', 1), 'a mark is never read as a verdict');
    }

    public function testAProofIsNeverReadAsAnUndecidedMark(): void
    {
        $cache = new ProofCache($this->directory);
        $cache->put('abc', [new SolverResult(Verdict::Unsat)]);

        self::assertFalse($cache->isUndecided('abc'));
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function damagedMarks(): iterable
    {
        yield 'not json' => ['{"format": 1, "ki'];
        yield 'an old format' => ['{"format":0,"kind":"undecided","key":"abc"}'];
        yield 'a newer format' => ['{"format":2,"kind":"undecided","key":"abc"}'];
        yield 'another kind' => ['{"format":1,"kind":"proof","key":"abc"}'];
        yield 'the mark of another key' => ['{"format":1,"kind":"undecided","key":"abd"}'];
        yield 'no key' => ['{"format":1,"kind":"undecided"}'];
        yield 'a verdict instead' => ['{"format":1,"results":[{"verdict":"unsat","values":[]}]}'];
        yield 'empty' => [''];
    }

    #[\PHPUnit\Framework\Attributes\DataProvider('damagedMarks')]
    public function testADamagedMarkIsIgnored(string $content): void
    {
        mkdir($this->directory, 0777, true);
        file_put_contents($this->directory . DIRECTORY_SEPARATOR . 'undecided-abc.json', $content);

        self::assertFalse((new ProofCache($this->directory))->isUndecided('abc'));
    }

    public function testADisabledCacheRemembersNothing(): void
    {
        $cache = new ProofCache(null);
        $cache->markUndecided('abc');

        self::assertFalse($cache->isUndecided('abc'));
    }
}