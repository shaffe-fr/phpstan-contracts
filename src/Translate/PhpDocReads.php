<?php

declare(strict_types=1);

namespace PhpstanContracts\Translate;

use PhpstanContracts\Exception\VerificationRejected;
use PhpParser\Comment\Doc;
use PhpParser\Node;
use PhpParser\Node\Expr\ClassConstFetch;
use PhpParser\Node\Expr\ConstFetch;
use PhpParser\Node\Identifier;
use PhpParser\Node\Name;
use PhpParser\Node\Stmt\ClassLike;
use PhpParser\Node\Stmt\ClassMethod;
use PhpParser\Node\Stmt\GroupUse;
use PhpParser\Node\Stmt\Namespace_;
use PhpParser\Node\Stmt\Use_;
use PHPStan\Analyser\NameScope;
use PHPStan\PhpDoc\TypeNodeResolverExtension;
use PHPStan\PhpDocParser\Ast\ConstExpr\ConstExprArrayItemNode;
use PHPStan\PhpDocParser\Ast\ConstExpr\ConstExprArrayNode;
use PHPStan\PhpDocParser\Ast\ConstExpr\ConstExprFalseNode;
use PHPStan\PhpDocParser\Ast\ConstExpr\ConstExprFloatNode;
use PHPStan\PhpDocParser\Ast\ConstExpr\ConstExprIntegerNode;
use PHPStan\PhpDocParser\Ast\ConstExpr\ConstExprNode;
use PHPStan\PhpDocParser\Ast\ConstExpr\ConstExprNullNode;
use PHPStan\PhpDocParser\Ast\ConstExpr\ConstExprStringNode;
use PHPStan\PhpDocParser\Ast\ConstExpr\ConstExprTrueNode;
use PHPStan\PhpDocParser\Ast\ConstExpr\ConstFetchNode;
use PHPStan\PhpDocParser\Ast\Node as PhpDocNode;
use PHPStan\PhpDocParser\Ast\PhpDoc\TemplateTagValueNode;
use PHPStan\PhpDocParser\Ast\PhpDoc\TypeAliasImportTagValueNode;
use PHPStan\PhpDocParser\Ast\Type\ArrayShapeItemNode;
use PHPStan\PhpDocParser\Ast\Type\ArrayShapeNode;
use PHPStan\PhpDocParser\Ast\Type\ArrayTypeNode;
use PHPStan\PhpDocParser\Ast\Type\CallableTypeNode;
use PHPStan\PhpDocParser\Ast\Type\ConditionalTypeForParameterNode;
use PHPStan\PhpDocParser\Ast\Type\ConditionalTypeNode;
use PHPStan\PhpDocParser\Ast\Type\ConstTypeNode;
use PHPStan\PhpDocParser\Ast\Type\GenericTypeNode;
use PHPStan\PhpDocParser\Ast\Type\IdentifierTypeNode;
use PHPStan\PhpDocParser\Ast\Type\IntersectionTypeNode;
use PHPStan\PhpDocParser\Ast\Type\InvalidTypeNode;
use PHPStan\PhpDocParser\Ast\Type\NullableTypeNode;
use PHPStan\PhpDocParser\Ast\Type\ObjectShapeItemNode;
use PHPStan\PhpDocParser\Ast\Type\ObjectShapeNode;
use PHPStan\PhpDocParser\Ast\Type\OffsetAccessTypeNode;
use PHPStan\PhpDocParser\Ast\Type\ThisTypeNode;
use PHPStan\PhpDocParser\Ast\Type\TypeNode;
use PHPStan\PhpDocParser\Ast\Type\UnionTypeNode;
use PHPStan\PhpDocParser\Lexer\Lexer;
use PHPStan\PhpDocParser\Parser\ParserException;
use PHPStan\PhpDocParser\Parser\PhpDocParser;
use PHPStan\PhpDocParser\Parser\TokenIterator;
use PHPStan\Reflection\ClassReflection;
use Throwable;

/**
 * Records the classes and constants that the PHPDoc types of a class depend on, and
 * rejects the PHPDoc types whose dependencies are not known.
 *
 * PHPStan resolves a PHPDoc type before the translation sees it, and the result often
 * names nothing it was computed from: `int<0, Limits::MAX>` arrives as `int<0, 10>`,
 * `value-of<Priority>` as the backing values of the enum, an alias as its definition.
 * So the PHPDoc of every file declaring the class or one of its ancestors is read
 * again, and so are the definitions of the `typeAliases` parameter, which PHPStan
 * resolves without a namespace, and the project's stub files.
 *
 * Each type is checked against the forms PHPStan's own resolver knows. Their inputs are
 * the names written in them, so every name is looked up through {@see Lookups}, as a
 * class (a template or an alias name is a missing class, which is harmless) and, when
 * written in capitals, as a global constant, and every class constant's class is too.
 * A found class records its file and its ancestors' files, so a constant inherited from
 * a parent or a trait and the template types behind `template-type<>` are covered. The
 * value of each constant named (`X::MAX`, `self::MAX`, `X::PREFIX_*`, a global constant)
 * and of each case of an enum named (`value-of<>`) is followed through its initializer
 * by {@see ConstantGuard::foldedReads()}, since `MAX = Other::MAX` reads another file.
 * The source of an imported alias is read in turn, since the alias is written there.
 *
 * A type that another PHPStan extension resolves, a form this list does not know, or a
 * constant whose value cannot be followed can read anything: it is rejected with what
 * to write instead, never accepted silently. A PHPDoc in a method only rejects the
 * proofs that use that method (PHPStan gives a method the PHPDoc of the method it
 * overrides, so the name decides); any other PHPDoc rejects every proof using the class.
 *
 * Every PHPDoc of those files is read, not only the ones the proof uses: recording
 * too much re-analyses a file for nothing, recording too little keeps a stale proof.
 *
 * A Site is where a PHPDoc is, and the lower-case name of the method it is in. A refusal
 * is kept with the method it applies to, null for the whole class.
 *
 * @phpstan-type Site array{string, string|null}
 * @phpstan-type Refusals list<array{string|null, string}>
 * @phpstan-type Names array{site: Site, classes: list<array{string, Site}>, fetches: list<array{string, string, string, string|null, Site}>, constants: list<array{string, NameContext, Site}>, imports: array<string, string>, templateBounds: array<string, list<string>>, docsRead: array<int, true>, refusals: Refusals}
 */
final class PhpDocReads
{
    /**
     * Words PHP reserves, which can never name a class or a constant.
     */
    private const RESERVED = ['array', 'bool', 'callable', 'false', 'float', 'int', 'iterable', 'list', 'mixed', 'never', 'null', 'object', 'parent', 'self', 'static', 'string', 'true', 'void'];

    /** @var array<string, array{list<string>, Refusals, Dependencies}> sha1 of a file => the classes its aliases are imported from, why its PHPDoc is rejected, and what it names */
    private array $files = [];

    /** @var array{Refusals, Dependencies}|null why the configured aliases and stubs are rejected, and what they name */
    private ?array $configured = null;

    /**
     * @param array<string, string> $typeAliases the `typeAliases` parameter: alias => definition
     * @param list<string> $stubFiles the `stubFiles` parameter, whose PHPDoc replaces that of the classes they declare
     * @param list<TypeNodeResolverExtension> $typeExtensions the PHPDoc types of other PHPStan extensions
     */
    public function __construct(
        private readonly Lookups $lookups,
        private readonly SourceFiles $sources,
        private readonly ConstantGuard $constants,
        private readonly Lexer $lexer,
        private readonly PhpDocParser $parser,
        private readonly array $typeAliases = [],
        private readonly array $stubFiles = [],
        private readonly array $typeExtensions = [],
    ) {
    }

    /**
     * The files of the class and its ancestors, and what their PHPDoc and the configuration
     * name.
     *
     * @param string|null $method the method whose PHPDoc the proof uses, besides the PHPDoc outside methods
     * @throws VerificationRejected at $line when one of those PHPDoc types cannot be followed
     */
    public function record(ClassReflection $class, int $line, ?string $method = null): void
    {
        $refusals = $this->recordConfiguration();
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
                [$imports, $fileRefusals] = $this->readFile($file);
                $refusals = [...$refusals, ...$fileRefusals];
                foreach ($imports as $importedFrom) {
                    if ($this->lookups->hasClass($importedFrom)) {
                        $pending[] = $this->lookups->getClass($importedFrom);
                    }
                }
            }
        }

        foreach ($refusals as [$scope, $refusal]) {
            if ($scope === null || ($method !== null && $scope === strtolower($method))) {
                throw new VerificationRejected($refusal, $line);
            }
        }
    }

    /**
     * The aliases of the `typeAliases` parameter, and the stub files of the project: which
     * of their PHPDoc types the proof uses is not known, so all of them are recorded.
     *
     * @return Refusals
     */
    private function recordConfiguration(): array
    {
        if ($this->configured !== null) {
            $this->lookups->replay($this->configured[1]);

            return $this->configured[0];
        }
        $this->configured = $this->lookups->capture(function (): array {
            // PHPStan resolves them in a global name scope.
            $global = new NameContext(null);
            $names = self::noNames('');
            foreach ($this->typeAliases as $alias => $definition) {
                $names['site'] = [sprintf('the definition of the alias %s in the typeAliases parameter', $alias), null];
                $this->collectDoc('/** @var ' . $definition . ' */', $global, $names);
            }
            $refusals = [...$names['refusals'], ...$this->lookUp($names)];
            foreach ($this->stubFiles as $stub) {
                // The stubs PHPStan ships describe built-in classes and change only with PHPStan.
                if (!str_starts_with($stub, 'phar://') && is_file($stub)) {
                    $refusals = [...$refusals, ...$this->recordNames($stub)[1]];
                }
            }

            return $refusals;
        });

        return $this->configured[0];
    }

    /**
     * @return array{list<string>, Refusals} the classes the type aliases of the file are imported from, and why its PHPDoc is rejected
     */
    private function readFile(string $file): array
    {
        $key = sha1_file($file);
        if ($key !== false && isset($this->files[$key])) {
            [$imports, $refusals, $dependencies] = $this->files[$key];
            $this->lookups->replay($dependencies);
            return [$imports, $refusals];
        }
        [[$imports, $refusals], $dependencies] = $this->lookups->capture(fn (): array => $this->recordNames($file));
        if ($key !== false) {
            $this->files[$key] = [$imports, $refusals, $dependencies];
        }
        return [$imports, $refusals];
    }

    /**
     * @return array{list<string>, Refusals}
     */
    private function recordNames(string $file): array
    {
        $statements = $this->sources->tryParse($file);
        if (is_string($statements)) {
            // PHPStan could not read the PHPDoc of this file either.
            return [[], []];
        }
        $names = self::noNames($file);
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

        return [array_values($names['imports']), [...$names['refusals'], ...$this->lookUp($names)]];
    }

    /**
     * @return Names
     */
    private static function noNames(string $file): array
    {
        return ['site' => [$file, null], 'classes' => [], 'fetches' => [], 'constants' => [], 'imports' => [], 'templateBounds' => [], 'docsRead' => [], 'refusals' => []];
    }

    /**
     * Looks up every name collected, and follows the initializers of the constants and
     * enum cases PHPStan folds into the types, which can read other files in turn.
     *
     * @param Names $names
     * @return Refusals for the values that cannot be followed
     */
    private function lookUp(array $names): array
    {
        $refusals = [];
        foreach ($names['classes'] as [$name, $site]) {
            if (!$this->lookups->hasClass($name)) {
                continue;
            }
            // value-of<> reads the backing values of an enum.
            $class = $this->lookups->getClass($name);
            if ($class->isBackedEnum()) {
                foreach (array_keys($class->getEnumCases()) as $case) {
                    $refusals = [...$refusals, ...$this->followFetch($class, $case, $site)];
                }
            }
        }
        foreach ($names['fetches'] as [$written, $resolved, $constant, $enclosing, $site]) {
            $context = $enclosing !== null && $this->lookups->hasClass($enclosing) ? $this->lookups->getClass($enclosing) : null;
            $classes = match (strtolower($written)) {
                'self', 'static' => [$context],
                'parent' => [$context?->getParentClass()],
                // `T::MAX` with T bound to a final class reads the constant of that class.
                default => [$resolved, ...$names['templateBounds'][$written] ?? []],
            };
            foreach ($classes as $candidate) {
                $class = is_string($candidate) && $this->lookups->hasClass($candidate) ? $this->lookups->getClass($candidate) : $candidate;
                if (!$class instanceof ClassReflection) {
                    continue;
                }
                foreach (self::matching($class, $constant) as $name) {
                    $refusals = [...$refusals, ...$this->followFetch($class, $name, $site)];
                }
            }
        }
        foreach ($names['constants'] as [$name, $context, $site]) {
            // PHPStan reads an upper-case name as a global constant when no class has that name.
            $this->lookups->hasClass($context->resolveClass($name));
            foreach ($context->resolveConstant($name) as $candidate) {
                $constant = new Name\FullyQualified($candidate);
                if ($this->lookups->hasConstant($constant, null)) {
                    $refusals = [...$refusals, ...self::followed($this->constants->foldedReads(new ConstFetch($constant), null), $site)];
                    break;
                }
            }
        }

        return $refusals;
    }

    /**
     * @param Site $site
     * @return Refusals
     */
    private function followFetch(ClassReflection $class, string $constant, array $site): array
    {
        $fetch = new ClassConstFetch(new Name\FullyQualified($class->getName()), new Identifier($constant));

        return self::followed($this->constants->foldedReads($fetch, $class), $site);
    }

    /**
     * @param Site $site
     * @return Refusals
     */
    private static function followed(?string $unfollowed, array $site): array
    {
        return $unfollowed === null ? [] : [[$site[1], sprintf(
            'The PHPDoc type in %s reads %s, so the proof would not be redone when it changes. Write the value as a literal, such as int<0, 100>.',
            $site[0],
            $unfollowed,
        )]];
    }

    /**
     * The constants and cases of the class that `X::NAME` or `X::PREFIX_*` designates.
     *
     * @return list<string>
     */
    private static function matching(ClassReflection $class, string $pattern): array
    {
        if (!str_contains($pattern, '*')) {
            return [$pattern];
        }
        $regex = '(^' . str_replace('\\*', '.*', preg_quote($pattern)) . '$)';

        return array_values(array_filter(
            array_keys($class->getNativeReflection()->getConstants()),
            static fn (string $name): bool => preg_match($regex, $name) === 1,
        ));
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
        $enclosingClass = $context->className;
        $enclosingSite = $names['site'];
        if ($node instanceof ClassLike && $node->namespacedName !== null) {
            $context->className = $node->namespacedName->toString();
        }
        if ($node instanceof ClassMethod) {
            $names['site'] = [$names['site'][0], $node->name->toLowerString()];
        }
        foreach ($node->getComments() as $comment) {
            // A comment before a statement is also attached to the first expression in it.
            if ($comment instanceof Doc && !isset($names['docsRead'][$comment->getStartFilePos()])) {
                $names['docsRead'][$comment->getStartFilePos()] = true;
                $site = $names['site'];
                $names['site'] = [sprintf('%s on line %d', $site[0], $comment->getStartLine()), $site[1]];
                $this->collectDoc($comment->getText(), $context, $names);
                $names['site'] = $site;
            }
        }
        if (!$node instanceof Namespace_) {
            foreach ($node->getSubNodeNames() as $subNodeName) {
                $children = $node->$subNodeName;
                foreach (is_array($children) ? $children : [$children] as $child) {
                    if ($child instanceof Node) {
                        $this->collectDocs($child, $context, $names);
                    }
                }
            }
        }
        $context->className = $enclosingClass;
        $names['site'] = $enclosingSite;
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
     * A node of a PHPDoc that is not a type: a tag, or a part of a type.
     *
     * @param Names $names
     */
    private function collectNode(PhpDocNode $node, NameContext $context, array &$names): void
    {
        if ($node instanceof TypeNode) {
            $this->collectType($node, $context, $names);
            return;
        }
        if ($node instanceof ConstExprNode) {
            $this->collectConstant($node, $context, $names);
            return;
        }
        if (str_starts_with($node::class, 'PHPStan\\PhpDocParser\\Ast\\PhpDoc\\Doctrine\\')) {
            // Doctrine annotations: PHPStan reads no type from them.
            return;
        }
        if ($node instanceof TypeAliasImportTagValueNode) {
            // The name as written, with a leading backslash when it is fully qualified.
            $importedFrom = $context->resolveClass((string) $node->importedFrom);
            $names['imports'][strtolower($importedFrom)] = $importedFrom;
            return;
        }
        if ($node instanceof TemplateTagValueNode && $node->bound !== null) {
            foreach (self::identifiers($node->bound) as $bound) {
                $names['templateBounds'][$node->name][] = $context->resolveClass($bound);
            }
        }
        if ($node instanceof ArrayShapeItemNode || $node instanceof ObjectShapeItemNode) {
            // A key is a literal, or a class constant.
            if ($node->keyName instanceof ConstFetchNode) {
                $this->collectConstant($node->keyName, $context, $names);
            }
            $this->collectType($node->valueType, $context, $names);
            return;
        }
        foreach (self::children($node) as $child) {
            $this->collectNode($child, $context, $names);
        }
    }

    /**
     * @param Names $names
     */
    private function collectType(TypeNode $node, NameContext $context, array &$names): void
    {
        $extension = $this->extensionResolving($node, $context);
        if ($extension !== null) {
            self::refuse($names, sprintf(
                'The PHPDoc type %s in %s is resolved by the PHPStan extension %s, and the files it reads cannot be followed, so the proof would not be redone when they change. Use a type PHPStan itself defines, such as int<0, 100> or a class name.',
                $node,
                $names['site'][0],
                $extension,
            ));
            return;
        }

        if ($node instanceof IdentifierTypeNode) {
            $this->collectName($node->name, $context, $names);
            return;
        }
        if ($node instanceof ThisTypeNode || $node instanceof InvalidTypeNode) {
            // The class itself, recorded; or a type PHPStan cannot read, which is mixed.
            return;
        }
        if (
            $node instanceof GenericTypeNode
            || $node instanceof UnionTypeNode
            || $node instanceof IntersectionTypeNode
            || $node instanceof NullableTypeNode
            || $node instanceof ArrayTypeNode
            || $node instanceof ArrayShapeNode
            || $node instanceof ObjectShapeNode
            || $node instanceof CallableTypeNode
            || $node instanceof ConditionalTypeNode
            || $node instanceof ConditionalTypeForParameterNode
            || $node instanceof OffsetAccessTypeNode
            || $node instanceof ConstTypeNode
        ) {
            foreach (self::children($node) as $child) {
                $this->collectNode($child, $context, $names);
            }
            return;
        }

        self::refuse($names, self::unknownForm($node, $names['site'][0]));
    }

    /**
     * @param Names $names
     */
    private function collectConstant(ConstExprNode $node, NameContext $context, array &$names): void
    {
        if ($node instanceof ConstFetchNode) {
            if ($node->className === '') {
                $names['constants'][] = [$node->name, clone $context, $names['site']];
            } else {
                $names['fetches'][] = [$node->className, $context->resolveClass($node->className), $node->name, $context->className, $names['site']];
            }
            return;
        }
        if ($node instanceof ConstExprArrayNode || $node instanceof ConstExprArrayItemNode) {
            foreach (self::children($node) as $child) {
                $this->collectNode($child, $context, $names);
            }
            return;
        }
        if (
            $node instanceof ConstExprIntegerNode
            || $node instanceof ConstExprFloatNode
            || $node instanceof ConstExprStringNode
            || $node instanceof ConstExprTrueNode
            || $node instanceof ConstExprFalseNode
            || $node instanceof ConstExprNullNode
        ) {
            return;
        }

        self::refuse($names, self::unknownForm($node, $names['site'][0]));
    }

    /**
     * A keyword, or a class, alias, template or constant name: every name is looked up,
     * since PHPStan resolves a keyword such as `integer` as a class when one has that name.
     *
     * @param Names $names
     */
    private function collectName(string $name, NameContext $context, array &$names): void
    {
        if (in_array(strtolower($name), self::RESERVED, true) || preg_match('(^\\\\?[A-Za-z_\x80-\xff][A-Za-z0-9_\x80-\xff]*(?:\\\\[A-Za-z_\x80-\xff][A-Za-z0-9_\x80-\xff]*)*$)', $name) !== 1) {
            // A keyword such as `non-empty-string`: PHPStan reads no other symbol for it.
            return;
        }
        $names['classes'][] = [$context->resolveClass($name), $names['site']];
        if (preg_match('((?:^|\\\\)[A-Z_][A-Z0-9_]*$)', $name) === 1) {
            $names['constants'][] = [$name, clone $context, $names['site']];
        }
    }

    /**
     * @param Names $names
     */
    private static function refuse(array &$names, string $refusal): void
    {
        $names['refusals'][] = [$names['site'][1], $refusal];
    }

    /**
     * The other PHPStan extension that resolves this type, if any. PHPStan asks them before
     * its own resolver, so what they read is unknown here.
     */
    private function extensionResolving(TypeNode $node, NameContext $context): ?string
    {
        if ($this->typeExtensions === []) {
            return null;
        }
        $namespace = $context->namespace === '' ? null : $context->namespace;
        $scope = new NameScope($namespace, $context->uses, $context->className, constUses: $context->constUses);
        foreach ($this->typeExtensions as $extension) {
            try {
                if ($extension->resolve($node, $scope) !== null) {
                    return $extension::class;
                }
            } catch (Throwable) {
                // It did not answer for this scope; it could answer for the real one.
                return $extension::class;
            }
        }

        return null;
    }

    private static function unknownForm(PhpDocNode $node, string $where): string
    {
        return sprintf(
            'The PHPDoc type %s in %s uses a form whose dependencies are not known, so the proof would not be redone when the files it reads change. Use a type PHPStan itself defines, such as int<0, 100> or a class name.',
            $node,
            $where,
        );
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
