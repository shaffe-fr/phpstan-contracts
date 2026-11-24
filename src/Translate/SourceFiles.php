<?php

declare(strict_types=1);

namespace PhpstanContracts\Translate;

use PhpstanContracts\Exception\VerificationRejected;
use PhpParser\Error;
use PhpParser\Node;
use PhpParser\NodeTraverser;
use PhpParser\NodeVisitor\NameResolver;
use PhpParser\Parser;
use PhpParser\ParserFactory;

/**
 * The PHP files the translation reads directly: the bodies of called methods and
 * constructors, and the files defining global constants. Each read is recorded in
 * {@see Lookups}, also when the statements come from the cache, so that every proof
 * that reads a file depends on it.
 *
 * The cache is keyed by the contents, so an edited file is parsed again.
 */
final class SourceFiles
{
    private const CAPACITY = 32;

    private const UNREADABLE = 'unreadable';

    private const UNPARSABLE = 'unparsable';

    /** @var array<string, array<Node>> sha1 of the contents => statements with every name resolved */
    private array $parsed = [];

    private readonly Parser $parser;

    public function __construct(private readonly Lookups $lookups)
    {
        $this->parser = (new ParserFactory())->createForNewestSupportedVersion();
    }

    /**
     * @return array<Node>
     */
    public function parse(string $path, int $line): array
    {
        $statements = $this->load($path);

        return match ($statements) {
            self::UNREADABLE => throw new VerificationRejected(sprintf('Cannot read %s.', $path), $line),
            self::UNPARSABLE => throw new VerificationRejected(sprintf('Cannot parse %s.', $path), $line),
            default => $statements,
        };
    }

    /**
     * Like {@see parse()}, for a caller that turns the failure into its own message.
     *
     * @return array<Node>|string the statements, or why they cannot be had: `%s cannot be read` or `%s cannot be parsed`
     */
    public function tryParse(string $path): array|string
    {
        $statements = $this->load($path);

        return match ($statements) {
            self::UNREADABLE => sprintf('%s cannot be read', $path),
            self::UNPARSABLE => sprintf('%s cannot be parsed', $path),
            default => $statements,
        };
    }

    /**
     * @return array<Node>|self::UNREADABLE|self::UNPARSABLE
     */
    private function load(string $path): array|string
    {
        $this->lookups->recordFile($path);

        $code = @file_get_contents($path);
        if ($code === false) {
            return self::UNREADABLE;
        }

        $key = sha1($code);
        if (isset($this->parsed[$key])) {
            return $this->parsed[$key];
        }

        try {
            $statements = $this->parser->parse($code) ?? [];
        } catch (Error) {
            return self::UNPARSABLE;
        }

        if (count($this->parsed) >= self::CAPACITY) {
            unset($this->parsed[array_key_first($this->parsed)]);
        }

        return $this->parsed[$key] = (new NodeTraverser(new NameResolver()))->traverse($statements);
    }
}
