<?php

declare(strict_types=1);

namespace PhpstanContracts\Tests\Unit\Report;

use PhpstanContracts\Report\CostPrediction;
use PhpstanContracts\Report\TimingReport;
use PhpstanContracts\Solver\MethodAnswer;
use PhpstanContracts\Solver\SolverResult;
use PhpstanContracts\Solver\UnknownReason;
use PhpstanContracts\Solver\Verdict;
use FilesystemIterator;
use PHPUnit\Framework\TestCase;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;

final class TimingReportTest extends TestCase
{
    private string $directory;

    protected function setUp(): void
    {
        $this->directory = sys_get_temp_dir() . '/contracts-timing-' . bin2hex(random_bytes(6));
    }

    protected function tearDown(): void
    {
        if (!is_dir($this->directory)) {
            return;
        }
        $items = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($this->directory, FilesystemIterator::SKIP_DOTS), RecursiveIteratorIterator::CHILD_FIRST);
        foreach ($items as $item) {
            $item->isDir() ? rmdir($item->getPathname()) : unlink($item->getPathname());
        }
        rmdir($this->directory);
    }

    public function testAMethodAppendsOneJsonLineWithTheStructureTheMeasuredTimeAndTheVerdicts(): void
    {
        $path = $this->directory . '/nested/report.jsonl';
        $report = new TimingReport($path);
        $answer = new MethodAnswer(
            [
                new SolverResult(Verdict::Unsat),
                new SolverResult(Verdict::Sat, []),
                new SolverResult(Verdict::Unknown),
                new SolverResult(Verdict::Unknown, [], UnknownReason::Budget),
                new SolverResult(Verdict::Unknown, [], UnknownReason::Remembered),
            ],
            [],
            120,
            30,
            40,
            true,
        );

        $report->append('App\\Cart::total', 'C:\\app\\Cart.php', $this->prediction(), $answer, 'exhausted');
        $report->append('App\\Cart::tax', '/app/Cart.php', $this->prediction(), null, 'runExhausted');

        $lines = file($path, FILE_IGNORE_NEW_LINES);
        self::assertIsArray($lines);
        self::assertCount(2, $lines, 'the file is appended to, one line per method');
        $first = json_decode($lines[0], true, 512, JSON_THROW_ON_ERROR);
        self::assertSame('App\\Cart::total', $first['method']);
        self::assertSame('C:\\app\\Cart.php', $first['file']);
        self::assertSame($this->prediction()->toArray(), $first['predicted']);
        self::assertSame(['main' => 120, 'retry' => 30, 'split' => 40], $first['measuredMs']);
        self::assertSame(['sat' => 1, 'unsat' => 1, 'unknown' => 3, 'notTried' => 1, 'remembered' => 1], $first['verdicts']);
        self::assertSame('exhausted', $first['budget']);
        $second = json_decode($lines[1], true, 512, JSON_THROW_ON_ERROR);
        self::assertSame(['main' => 0, 'retry' => 0, 'split' => 0], $second['measuredMs']);
        self::assertSame('runExhausted', $second['budget']);
    }

    public function testWithoutAPathNothingIsWritten(): void
    {
        $report = new TimingReport(null);

        $report->append('A::b', 'a.php', $this->prediction(), null, 'within');

        self::assertFalse($report->isEnabled());
        self::assertFalse(is_dir($this->directory));
    }

    public function testParallelWorkersAppendWholeLinesToTheSameFile(): void
    {
        $path = $this->directory . '/parallel.jsonl';
        mkdir($this->directory, 0777, true);
        $autoload = dirname(__DIR__, 3) . '/vendor/autoload.php';
        $code = sprintf(
            'require %s; $r = new PhpstanContracts\Report\TimingReport(%s); $p = new PhpstanContracts\Report\CostPrediction(1, [], 0, 0, 0, 0, 1, false);'
            . ' for ($i = 0; $i < 40; ++$i) { $r->append("W" . $argv[1] . "::" . $i, str_repeat("x", 4000), $p, null, "within"); }',
            var_export($autoload, true),
            var_export($path, true),
        );

        $processes = [];
        for ($worker = 0; $worker < 4; ++$worker) {
            $process = proc_open([PHP_BINARY, '-r', $code, '--', (string) $worker], [1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes);
            self::assertIsResource($process);
            $processes[] = [$process, $pipes];
        }
        foreach ($processes as [$process, $pipes]) {
            $output = stream_get_contents($pipes[1]) . stream_get_contents($pipes[2]);
            self::assertSame(0, proc_close($process), $output);
        }

        $lines = file($path, FILE_IGNORE_NEW_LINES);
        self::assertIsArray($lines);
        self::assertCount(4 * 40, $lines);
        $methods = [];
        foreach ($lines as $line) {
            $record = json_decode($line, true, 512, JSON_THROW_ON_ERROR);
            self::assertIsArray($record);
            $methods[] = $record['method'];
        }
        self::assertCount(160, array_unique($methods), 'no line is lost or mixed with another');
    }

    private function prediction(): CostPrediction
    {
        return new CostPrediction(5, [['line' => 12, 'bound' => 3, 'depth' => 0]], 3, 6, 3, 5, 3000, true);
    }
}
