<?php

declare(strict_types=1);

namespace PhpstanContracts\Translate;

use PhpParser\Comment\Doc;
use PhpParser\Node;
use PhpParser\Node\Name;
use PhpParser\Node\Stmt\GroupUse;
use PhpParser\Node\Stmt\Namespace_;
use PhpParser\Node\Stmt\Use_;
use PHPStan\PhpDocParser\Ast\ConstExpr\ConstFetchNode;
use PHPStan\PhpDocParser\Ast\Node as PhpDocNode;
use PHPStan\PhpDocParser\Ast\PhpDoc\TemplateTagValueNode;
use PHPStan\PhpDocParser\Ast\PhpDoc\TypeAliasImportTagValueNode;
use PHPStan\PhpDocParser\Ast\Type\IdentifierTypeNode;
use PHPStan\PhpDocParser\Lexer\Lexer;
use PHPStan\PhpDocParser\Parser\ParserException;
use PHPStan\PhpDocParser\Parser\PhpDocParser;
use PHPStan\PhpDocParser\Parser\TokenIterator;
use PHPStan\Reflection\ClassReflection;

/**
 * Records the constants and classes that the PHPDoc types of a class depend on.
 *
 * PHPStan folds the constants of a PHPDoc type before the translation sees it:
 * `int<0, Limits::MAX>` arrives as `int<0, 10>`, with nothing left that names
 * Limits. So the PHPDoc of every file declaring the class or one of its ancestors
 * is read again, and each class constant (`X::MAX`, `X::A|X::B`, `X::PREFIX_*`,
 * `key-of<X::MAP>`), global constant and `@phpstan-import-type` source it names
 * is looked up through {@see Lookups}. The source of an imported alias is read in
 * turn, since the alias is written there.
 *
 * Every PHPDoc of those files is read, not only the ones the proof uses: recording
 * too much re-analyses a file for nothing, recording too little keeps a stale proof.
 *
 * @phpstan-type Names array{classes: list<string>, constants: list<array{string, NameContext}>, imports: array<string, string>, templateFetches: array<string, true>, templateBounds: array<string, list<string>>, docsRead: array<int, true>}
 */
final class PhpDocReads
{
    /** @var array<string, array{list<string>, Dependencies}> sha1 of a file => the classes its aliases are imported from, and what its PHPDoc names */
    private array $files = [];

    public function __construct(
        private readonly Lookups $lookups,
        private readonly SourceFiles $sources,
        private readonly Lexer $lexer,
        private readonly PhpDocParser $parser,
    ) {
    }

    /**
     * The files of the class and its ancestors, and what their PHPDoc names.
     */
    public function record(ClassReflection $class): void
    {
        $pending = [$class];
        $seen = [];
        while (($next = array_pop($pending)) !== null) {
            if (isset($seen[strtolower($next->getName())])) {
                continue;
            }
            $seen[strtolower($next->getName())] = true;
            $this->lookups->recordClass($next);
            foreach ([$next, ...array_values($next->getAncestors())] as $ancestor) {
                $file = $ancestor->getFileName();
                if ($ancestor->isBuiltin() || $file === null || !is_file($file)) {
                    continue;
                }
                foreach ($this->readFile($file) as $importedFrom) {
                    if ($this->lookups->hasClass($importedFrom)) {
                        $pending[] = $this->lookups->getClass($importedFrom);
                    }
                }
            }
        }
    }

    /**
     * @return list<string> the classes the type aliases of the file are imported from
     */
    private function readFile(string $file): array
    {
        $key = sha1_file($file);
        if ($key !== false && isset($this->files[$key])) {
            [$imports, $dependencies] = $this->files[$key];
            $this->lookups->replay($dependencies);
            return $imports;
        }
        $read = $this->lookups->capture(fn (): array => $this->recordNames($file));
        if ($key !== false) {
            $this->files[$key] = $read;
        }
        return $read[0];
    }

    /**
     * @return list<string>
     */
    private function recordNames(string $file): array
    {
        $statements = $this->sources->tryParse($file);
        if (is_string($statements)) {
            // PHPStan could not read the PHPDoc of this file either.
            return [];
        }
        /** @var Names $names */
        $names = ['classes' => [], 'constants' => [], 'imports' => [], 'templateFetches' => [], 'templateBounds' => [], 'docsRead' => []];
        $global = new NameContext(null);
        foreach ($statements as $statement) {
            if ($statement instanceof Namespace_) {
                $this->collectDocs($statement, $global, $names);
                $inner = new NameContext($statement->name?->toString());
                foreach ($statement->stmts as $inside) {
                    $this->collectStatement($inside, $inner, $names);
                }
                continue;
            }
            $this->collectStatement($statement, $global, $names);
        }

        foreach ($names['classes'] as $class) {
            $this->lookups->hasClass($class);
        }
        foreach (array_keys($names['templateFetches']) as $template) {
            foreach ($names['templateBounds'][$template] ?? [] as $bound) {
                $this->lookups->hasClass($bound);
            }
        }
        foreach ($names['constants'] as [$name, $context]) {
            // PHPStan reads an upper-case name as a global constant when no class has that name.
            $this->lookups->hasClass($context->resolveClass($name));
            foreach ($context->resolveConstant($name) as $candidate) {
                $constant = new Name\FullyQualified($candidate);
                if ($this->lookups->hasConstant($constant, null)) {
                    $this->lookups->getConstant($constant, null);
                    break;
                }
            }
        }

        return array_values($names['imports']);
    }

    /**
     * @param Names $names
     */
    private function collectStatement(Node $statement, NameContext $context, array &$names): void
    {
        if ($statement instanceof Use_ || $statement instanceof GroupUse) {
            $prefix = $statement instanceof GroupUse ? $statement->prefix->toString() . '\\' : '';
            foreach ($statement->uses as $use) {
                $type = $statement->type === Use_::TYPE_UNKNOWN ? $use->type : $statement->type;
                $alias = strtolower($use->getAlias()->toString());
                if ($type === Use_::TYPE_NORMAL) {
                    $context->uses[$alias] = $prefix . $use->name->toString();
                } elseif ($type === Use_::TYPE_CONSTANT) {
                    $context->constUses[$alias] = $prefix . $use->name->toString();
                }
            }
        }
        $this->collectDocs($statement, $context, $names);
    }

    /**
     * The doc comments of the node and of every node inside it.
     *
     * @param Names $names
     */
    private function collectDocs(Node $node, NameContext $context, array &$names): void
    {
        foreach ($node->getComments() as $comment) {
            // A comment before a statement is also attached to the first expression in it.
            if ($comment instanceof Doc && !isset($names['docsRead'][$comment->getStartFilePos()])) {
                $names['docsRead'][$comment->getStartFilePos()] = true;
                $this->collectDoc($comment->getText(), $context, $names);
            }
        }
        if ($node instanceof Namespace_) {
            return;
        }
        foreach ($node->getSubNodeNames() as $subNodeName) {
            $children = $node->$subNodeName;
            foreach (is_array($children) ? $children : [$children] as $child) {
                if ($child instanceof Node) {
                    $this->collectDocs($child, $context, $names);
                }
            }
        }
    }

    /**
     * @param Names $names
     */
    private function collectDoc(string $text, NameContext $context, array &$names): void
    {
        try {
            $doc = $this->parser->parse(new TokenIterator($this->lexer->tokenize($text)));
        } catch (ParserException) {
            // PHPStan gets no type from a PHPDoc it cannot parse.
            return;
        }
        $this->collectNode($doc, $context, $names);
    }

    /**
     * @param Names $names
     */
    private function collectNode(PhpDocNode $node, NameContext $context, array &$names): void
    {
        if ($node instanceof ConstFetchNode && $node->className !== '') {
            // The constants of `self`, `static` and `parent` are in the class or its ancestors, which are recorded.
            if (!in_array(strtolower($node->className), ['self', 'static', 'parent'], true)) {
                $names['classes'][] = $context->resolveClass($node->className);
                $names['templateFetches'][$node->className] = true;
            }
        } elseif ($node instanceof IdentifierTypeNode && preg_match('((?:^|\\\\)[A-Z_][A-Z0-9_]*$)', $node->name) === 1) {
            $names['constants'][] = [$node->name, clone $context];
        } elseif ($node instanceof TypeAliasImportTagValueNode) {
            // The name as written, with a leading backslash when it is fully qualified.
            $importedFrom = $context->resolveClass((string) $node->importedFrom);
            $names['imports'][strtolower($importedFrom)] = $importedFrom;
        } elseif ($node instanceof TemplateTagValueNode && $node->bound !== null) {
            // `T::MAX` with T bound to a final class reads the constant of that class.
            foreach (self::identifiers($node->bound) as $bound) {
                $names['templateBounds'][$node->name][] = $context->resolveClass($bound);
            }
        }
        foreach (self::children($node) as $child) {
            $this->collectNode($child, $context, $names);
        }
    }

    /**
     * @return list<string>
     */
    private static function identifiers(PhpDocNode $node): array
    {
        $found = $node instanceof IdentifierTypeNode ? [$node->name] : [];
        foreach (self::children($node) as $child) {
            $found = [...$found, ...self::identifiers($child)];
        }
        return $found;
    }

    /**
     * @return list<PhpDocNode>
     */
    private static function children(PhpDocNode $node): array
    {
        $children = [];
        foreach (get_object_vars($node) as $value) {
            foreach (is_array($value) ? $value : [$value] as $child) {
                if ($child instanceof PhpDocNode) {
                    $children[] = $child;
                }
            }
        }
        return $children;
    }
}
