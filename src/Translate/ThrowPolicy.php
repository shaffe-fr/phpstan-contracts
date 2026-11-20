<?php

declare(strict_types=1);

namespace PhpstanContracts\Translate;

use PhpstanContracts\Smt\Smt;
use PhpstanContracts\Smt\Term;
use PHPStan\Reflection\ClassReflection;

/**
 * The exceptions a method declares with `#[Throws]`, each with its condition on
 * the inputs, and the paths on which the body throws.
 */
final class ThrowPolicy
{
    /** @var list<Term> */
    private array $pending = [];

    private bool $throws = false;

    /**
     * @param list<array{string, Term}> $declared class name => condition, true when none is given
     */
    public function __construct(private readonly array $declared)
    {
    }

    /**
     * The condition under which throwing `$thrown` is allowed: false when no
     * `#[Throws]` declares it or one of its parents.
     */
    public function allowed(ClassReflection $thrown): Term
    {
        $conditions = [];
        foreach ($this->declared as [$class, $when]) {
            if ($thrown->getName() === $class || in_array($class, $thrown->getParentClassesNames(), true) || $thrown->implementsInterface($class)) {
                $conditions[] = $when;
            }
        }

        return Smt::any(...$conditions);
    }

    public function record(Term $path): void
    {
        $this->pending[] = $path;
        $this->throws = true;
    }

    /**
     * The paths that threw since the last call, which the statement that holds them
     * must stop.
     *
     * @return list<Term>
     */
    public function take(): array
    {
        $pending = $this->pending;
        $this->pending = [];

        return $pending;
    }

    public function anyThrow(): bool
    {
        return $this->throws;
    }
}
