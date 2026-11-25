<?php

declare(strict_types=1);

namespace PhpstanContracts\Solver;

/**
 * Remembers the answers of Z3 on disk, keyed by the exact script that was sent and by the solver build.
 *
 * Only scripts whose every check was settled are stored. An `unknown` depends on the time limit and may
 * turn into an answer with a longer one, so a script that holds one is always sent to Z3 again.
 *
 * A check that stayed `unknown` after everything that is tried (see {@see MethodSolver}) is remembered
 * apart, as undecided under the limits of that run: it is a mark that spares the next run the same
 * work, never an answer, and `get()` does not read it. The time Z3 needs depends on the machine, and
 * the cache is local to it.
 *
 * The cache can only lose information, never invent it: an entry that cannot be read back exactly is
 * ignored, and a write that fails is skipped. Entries are trusted like the rest of PHPStan's temporary
 * directory, so a cache directory must not be writable by anyone who should not be able to turn a
 * failing proof green.
 */
final class ProofCache
{
    private const FORMAT = 1;

    /**
     * Bump it whenever the way an undecided check is asked again changes: the retry, the split by
     * length ({@see CaseSplit}), the seeds and the options of the cases ({@see Z3Runner}). What an
     * earlier run left undecided was undecided by the old strategy, and the new one may decide it.
     */
    public const STRATEGY_VERSION = 1;

    public function __construct(
        private readonly ?string $directory,
    ) {
    }

    public function isEnabled(): bool
    {
        return $this->directory !== null;
    }

    public function key(string $binary, string $script): string
    {
        return hash('sha256', self::FORMAT . "\0" . $this->identity($binary) . "\0" . $script);
    }

    /**
     * The key under which a check that stayed `unknown` after the whole strategy is remembered. It holds
     * everything that decides the outcome: the script of the check, the solver build, the two limits
     * and the strategy. A run that changes any of them finds nothing.
     */
    public function undecidedKey(string $binary, string $script, int $timeoutMs, int $methodBudgetMs): string
    {
        return hash('sha256', implode("\0", ['undecided', self::FORMAT, self::STRATEGY_VERSION, $timeoutMs, $methodBudgetMs, $this->identity($binary), $script]));
    }

    public function isUndecided(string $key): bool
    {
        $path = $this->undecidedPathOf($key);
        $json = $path === null || !is_file($path) ? false : @file_get_contents($path);
        if ($json === false) {
            return false;
        }
        $decoded = json_decode($json, true);

        return is_array($decoded)
            && ($decoded['format'] ?? null) === self::FORMAT
            && ($decoded['kind'] ?? null) === 'undecided'
            && ($decoded['key'] ?? null) === $key;
    }

    public function markUndecided(string $key): void
    {
        $json = json_encode(['format' => self::FORMAT, 'kind' => 'undecided', 'key' => $key]);
        if ($json !== false) {
            $this->write($this->undecidedPathOf($key), $json);
        }
    }

    /**
     * @return list<SolverResult>|null null when there is no usable entry
     */
    public function get(string $key, int $checkCount): ?array
    {
        $path = $this->pathOf($key);
        if ($path === null || !is_file($path)) {
            return null;
        }

        $json = @file_get_contents($path);
        if ($json === false) {
            return null;
        }

        $decoded = json_decode($json, true);
        if (!is_array($decoded) || ($decoded['format'] ?? null) !== self::FORMAT || !is_array($decoded['results'] ?? null)) {
            return null;
        }

        $entries = array_values($decoded['results']);
        if (count($entries) !== $checkCount) {
            return null;
        }

        $results = [];
        foreach ($entries as $entry) {
            $result = $this->restore($entry);
            if ($result === null) {
                return null;
            }
            $results[] = $result;
        }

        return $results;
    }

    /**
     * @param list<SolverResult> $results
     */
    public function put(string $key, array $results): void
    {
        $path = $this->pathOf($key);
        if ($path === null || $this->directory === null) {
            return;
        }

        $entries = [];
        foreach ($results as $result) {
            if ($result->verdict === Verdict::Unknown) {
                return;
            }
            $entries[] = ['verdict' => $result->verdict->value, 'values' => $result->values];
        }

        $json = json_encode(['format' => self::FORMAT, 'results' => $entries]);
        if ($json === false) {
            return;
        }

        $this->write($path, $json);
    }

    private function write(?string $path, string $json): void
    {
        if ($path === null || $this->directory === null) {
            return;
        }
        if (!is_dir($this->directory) && !@mkdir($this->directory, 0777, true) && !is_dir($this->directory)) {
            return;
        }

        // Written aside and renamed, so that a parallel worker never reads half an entry.
        $temporary = @tempnam($this->directory, 'proof');
        if ($temporary === false) {
            return;
        }

        if (@file_put_contents($temporary, $json) === false || !@rename($temporary, $path)) {
            @unlink($temporary);
        }
    }

    private function identity(string $binary): string
    {
        return realpath($binary) . "\0" . filesize($binary) . "\0" . filemtime($binary);
    }

    private function pathOf(string $key): ?string
    {
        return $this->directory === null ? null : $this->directory . DIRECTORY_SEPARATOR . $key . '.json';
    }

    private function undecidedPathOf(string $key): ?string
    {
        return $this->directory === null ? null : $this->directory . DIRECTORY_SEPARATOR . 'undecided-' . $key . '.json';
    }

    private function restore(mixed $entry): ?SolverResult
    {
        if (!is_array($entry) || !is_string($entry['verdict'] ?? null) || !is_array($entry['values'] ?? null)) {
            return null;
        }

        $verdict = Verdict::tryFrom($entry['verdict']);
        if ($verdict === null || $verdict === Verdict::Unknown) {
            return null;
        }

        $values = [];
        foreach ($entry['values'] as $name => $value) {
            if (!is_string($name) || !is_string($value) && !is_array($value)) {
                return null;
            }
            /** @var string|list<mixed> $value */
            $values[$name] = $value;
        }

        if ($verdict !== Verdict::Sat && $values !== []) {
            return null;
        }

        return new SolverResult($verdict, $values);
    }
}
