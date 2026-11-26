<?php

declare(strict_types=1);

namespace PhpstanContracts\Translate;

use PhpstanContracts\Exception\VerificationRejected;
use PhpParser\Error;
use PhpParser\Node;
use PhpParser\Node\Stmt\ClassLike;
use PhpParser\NodeFinder;
use PhpParser\NodeTraverser;
use PhpParser\NodeVisitor\NameResolver;
use PhpParser\Parser;
use PhpParser\ParserFactory;
use PHPStan\Reflection\ClassReflection;

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
     * Every declaration of a class in parsed statements. More than one means the file
     * declares it under conditions, which only running the file decides between, while
     * PHPStan reflects the first.
     *
     * @param array<Node> $statements
     * @return list<ClassLike>
     */
    public static function declarations(array $statements, string $className): array
    {
        /** @var list<ClassLike> */
        return (new NodeFinder())->find(
            $statements,
            static fn (Node $node): bool => $node instanceof ClassLike
                && $node->namespacedName !== null
                && strcasecmp($node->namespacedName->toString(), $className) === 0,
        );
    }

    /**
     * The first of a class and its ancestors (parents, interfaces, traits) that its file
     * declares more than once, with that file. The method, constructor, destructor,
     * properties and constants that PHPStan reflects can come from any of them, so any of
     * them declared twice makes the code that runs unknown.
     *
     * @return array{ClassReflection, string}|string|null the class and its file, why a file cannot be had (see {@see tryParse()}), or null when each is declared once
     */
    public function declaredMoreThanOnce(ClassReflection $class): array|string|null
    {
        foreach ($class->getAncestors() as $candidate) {
            // Null for a class built into PHP or an extension, or not in a file on disk.
            $file = $candidate->getFileName();
            if ($file === null) {
                continue;
            }
            $statements = $this->tryParse($file);
            if (is_string($statements)) {
                return $statements;
            }
            if (count(self::declarations($statements, $candidate->getName())) > 1) {
                return [$candidate, $file];
            }
        }

        return null;
    }

    /**
     * How an ancestor returned by {@see declaredMoreThanOnce()} relates to the class, such
     * as `parent class` or `trait`.
     */
    public static function relation(ClassReflection $ancestor, ClassReflection $class): string
    {
        return match (true) {
            $ancestor->isInterface() => 'interface',
            $ancestor->isTrait() => 'trait',
            $class->getParentClass()?->getName() === $ancestor->getName() => 'parent class',
            default => 'ancestor class',
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

        // Original names too, so that a message can quote a call as it is written.
        return $this->parsed[$key] = (new NodeTraverser(new NameResolver(null, ['preserveOriginalNames' => true])))->traverse($statements);
    }
}
