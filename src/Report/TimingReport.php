<?php

declare(strict_types=1);

namespace PhpstanContracts\Report;

use PhpstanContracts\Solver\MethodAnswer;
use PhpstanContracts\Solver\Verdict;

/**
 * Appends one JSON line per verified method to a file, with what the encoder predicted and the
 * milliseconds that the solver took. It is the only place where measured time appears: a diagnostic
 * is cached per file by PHPStan and must say the same thing on every run.
 *
 * The lines are appended under an exclusive lock, so that the workers of a parallel run can write to
 * the same file. The file is never truncated: delete it before a run to start a new report. A report
 * that cannot be written is skipped, and the analysis goes on.
 */
final class TimingReport
{
    public function __construct(
        private readonly ?string $path = null,
    ) {
    }

    public function isEnabled(): bool
    {
        return $this->path !== null && $this->path !== '';
    }

    /**
     * @param 'unlimited'|'within'|'exhausted'|'runExhausted' $budget
     */
    public function append(string $method, string $file, CostPrediction $prediction, ?MethodAnswer $answer, string $budget): void
    {
        if ($this->path === null || $this->path === '') {
            return;
        }

        $verdicts = ['sat' => 0, 'unsat' => 0, 'unknown' => 0, 'notTried' => 0, 'remembered' => 0];
        if ($answer !== null) {
            foreach ($answer->results as $result) {
                $verdicts[match ($result->verdict) {
                    Verdict::Sat => 'sat',
                    Verdict::Unsat => 'unsat',
                    Verdict::Unknown => 'unknown',
                }]++;
            }
            $verdicts['notTried'] = $answer->notTried();
            $verdicts['remembered'] = $answer->remembered();
        }

        $line = json_encode([
            'method' => $method,
            'file' => $file,
            'predicted' => $prediction->toArray(),
            'measuredMs' => [
                'main' => $answer === null ? 0 : $answer->mainMs,
                'retry' => $answer === null ? 0 : $answer->retryMs,
                'split' => $answer === null ? 0 : $answer->splitMs,
            ],
            'verdicts' => $verdicts,
            'budget' => $budget,
        ], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE);
        if ($line === false) {
            return;
        }

        $directory = dirname($this->path);
        if (!is_dir($directory) && !mkdir($directory, 0777, true) && !is_dir($directory)) {
            return;
        }

        file_put_contents($this->path, $line . "\n", FILE_APPEND | LOCK_EX);
    }
}
