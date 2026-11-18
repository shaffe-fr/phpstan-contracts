<?php

declare(strict_types=1);

namespace PhpstanContracts\Smt;

use RuntimeException;

/**
 * Minimal S-expression reader for Z3 answers. Atoms stay strings, lists become arrays.
 * String literals keep their quotes so that a string value can be told from a symbol.
 */
final class SExpr
{
    /**
     * @return list<string|list<mixed>>
     */
    public static function parse(string $text): array
    {
        preg_match_all('/\(|\)|"(?:[^"]|"")*"|\|[^|]*\||[^\s()]+/', $text, $matches);

        /** @var list<list<mixed>> $stack */
        $stack = [[]];
        foreach ($matches[0] as $token) {
            if ($token === '(') {
                $stack[] = [];
            } elseif ($token === ')') {
                if (count($stack) < 2) {
                    throw new RuntimeException('Unbalanced S-expression: ' . $text);
                }
                $done = array_pop($stack);
                $stack[count($stack) - 1][] = $done;
            } else {
                $stack[count($stack) - 1][] = str_starts_with($token, '|') ? trim($token, '|') : $token;
            }
        }

        if (count($stack) !== 1) {
            throw new RuntimeException('Unbalanced S-expression: ' . $text);
        }

        return $stack[0];
    }
}
