<?php

declare(strict_types=1);

namespace PhpstanContracts\Translate;

use PhpParser\Node\Name;
use PHPStan\Reflection\ClassReflection;
use PHPStan\Reflection\ConstantReflection;
use PHPStan\Reflection\NamespaceAnswerer;
use PHPStan\Reflection\ReflectionProvider;

/**
 * The only way into PHPStan's reflection from the translation, so that every proof
 * knows what it read: PHPStan re-analyses a file when the signatures it uses change,
 * not when a body, a private constant or an enum case attribute of another file does,
 * so {@see \PhpstanContracts\Rule\VerifyContractRule} declares each file read here to
 * the result cache.
 *
 * A class found records its file and the files of its parents, interfaces and traits.
 * A name that does not resolve is recorded too: creating it later can change what a
 * short name in a contract string means.
 *
 * Results kept across methods by a service (a memo) must carry what computing them read,
 * otherwise the next method that hits the memo records nothing: {@see capture()} returns
 * it and {@see replay()} records it again.
 */
final class Lookups
{
    /** @var array{files: array<string, string>, missingClasses: array<string, string>, symbols: array<string, string>} what is being recorded */
    private array $frame;

    /** @var list<array{files: array<string, string>, missingClasses: array<string, string>, symbols: array<string, string>}> the frames of the enclosing capture() calls, outermost first */
    private array $enclosing = [];

    public function __construct(private readonly ReflectionProvider $reflection)
    {
        $this->frame = self::emptyFrame();
    }

    public function hasClass(string $name): bool
    {
        if (!$this->reflection->hasClass($name)) {
            $name = ltrim($name, '\\');
            $this->top()['missingClasses'][strtolower($name)] = $name;

            return false;
        }
        $this->recordClass($this->reflection->getClass($name));

        return true;
    }

    public function getClass(string $name): ClassReflection
    {
        $class = $this->reflection->getClass($name);
        $this->recordClass($class);

        return $class;
    }

    public function hasFunction(Name $name, ?NamespaceAnswerer $namespace): bool
    {
        foreach (self::candidates($name, $namespace) as $candidate) {
            $this->recordSymbol('function:' . strtolower($candidate));
        }

        return $this->reflection->hasFunction($name, $namespace);
    }

    public function hasConstant(Name $name, ?NamespaceAnswerer $namespace): bool
    {
        foreach (self::candidates($name, $namespace) as $candidate) {
            $this->recordSymbol('constant:' . $candidate);
        }

        return $this->reflection->hasConstant($name, $namespace);
    }

    public function getConstant(Name $name, ?NamespaceAnswerer $namespace): ConstantReflection
    {
        foreach (self::candidates($name, $namespace) as $candidate) {
            $this->recordSymbol('constant:' . $candidate);
        }
        $constant = $this->reflection->getConstant($name, $namespace);
        $file = $constant->getFileName();
        if (!$constant->isBuiltin()->yes() && $file !== null && is_file($file)) {
            $this->recordFile($file);
        }

        return $constant;
    }

    /**
     * For a file read directly rather than through reflection.
     */
    public function recordFile(string $path): void
    {
        $path = realpath($path) ?: $path;
        $this->top()['files'][PHP_OS_FAMILY === 'Windows' ? strtolower($path) : $path] = $path;
    }

    /**
     * The file of the class and of each of its parents, interfaces and traits. The stubs
     * of built-in classes only change with PHP or PHPStan, which clear the result cache.
     */
    public function recordClass(ClassReflection $class): void
    {
        foreach ([$class, ...array_values($class->getAncestors())] as $ancestor) {
            $file = $ancestor->getFileName();
            if (!$ancestor->isBuiltin() && $file !== null && is_file($file)) {
                $this->recordFile($file);
            }
        }
    }

    /**
     * `@phpstan-import-type X from Y` makes the PHPDoc types of a class depend on Y,
     * whose file is not otherwise read.
     */
    public function recordTypeAliasImports(ClassReflection $class): void
    {
        foreach ([$class, ...array_values($class->getAncestors())] as $ancestor) {
            foreach ($ancestor->getResolvedPhpDoc()?->getTypeAliasImportTags() ?? [] as $import) {
                $this->hasClass($import->getImportedFrom());
            }
        }
    }

    /**
     * Runs $compute and returns its result with what it read, which is also recorded where
     * the call is made. What it read before throwing is recorded too.
     *
     * @template T
     * @param callable(): T $compute
     * @return array{T, Dependencies}
     */
    public function capture(callable $compute): array
    {
        $this->enclosing[] = $this->frame;
        $this->frame = self::emptyFrame();
        try {
            $result = $compute();
        } finally {
            $captured = self::dependenciesOf($this->frame);
            $this->frame = array_pop($this->enclosing);
            $this->replay($captured);
        }

        return [$result, $captured];
    }

    public function replay(Dependencies $dependencies): void
    {
        foreach ($dependencies->files as $file) {
            $this->recordFile($file);
        }
        foreach ($dependencies->missingClasses as $name) {
            $this->top()['missingClasses'][strtolower($name)] = $name;
        }
        foreach ($dependencies->symbols as $symbol) {
            $this->recordSymbol($symbol);
        }
    }

    /**
     * Everything recorded since the last drain, which starts a new recording.
     */
    public function drain(): Dependencies
    {
        // Frames are only left open by a capture() still running, which drain() is never called from.
        $frames = [...$this->enclosing, $this->frame];
        $this->enclosing = [];
        $this->frame = self::emptyFrame();
        foreach ($frames as $frame) {
            $this->replay(self::dependenciesOf($frame));
        }
        $drained = self::dependenciesOf($this->frame);
        $this->frame = self::emptyFrame();

        return $drained;
    }

    /**
     * The names PHPStan tries, in order: an unqualified name in a namespace is looked up
     * there first, then globally.
     *
     * @return list<string>
     */
    private static function candidates(Name $name, ?NamespaceAnswerer $namespace): array
    {
        $written = ltrim($name->toString(), '\\');
        $current = $namespace?->getNamespace();
        if ($name->isFullyQualified() || $current === null) {
            return [$written];
        }

        return [$current . '\\' . $written, $written];
    }

    private function recordSymbol(string $symbol): void
    {
        $this->top()['symbols'][$symbol] = $symbol;
    }

    /**
     * @return array{files: array<string, string>, missingClasses: array<string, string>, symbols: array<string, string>}
     */
    private function &top(): array
    {
        return $this->frame;
    }

    /**
     * @return array{files: array<string, string>, missingClasses: array<string, string>, symbols: array<string, string>}
     */
    private static function emptyFrame(): array
    {
        return ['files' => [], 'missingClasses' => [], 'symbols' => []];
    }

    /**
     * @param array{files: array<string, string>, missingClasses: array<string, string>, symbols: array<string, string>} $frame
     */
    private static function dependenciesOf(array $frame): Dependencies
    {
        return new Dependencies(array_values($frame['files']), array_values($frame['missingClasses']), array_values($frame['symbols']));
    }
}
