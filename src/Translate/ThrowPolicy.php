<?php

declare(strict_types=1);

namespace PhpstanContracts\Translate;

use PhpstanContracts\Smt\Smt;
use PhpstanContracts\Smt\Term;
use PHPStan\Reflection\ClassReflection;
use PHPStan\Reflection\ReflectionProvider;

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
     * @param list<array{string, Term|null, string}> $declared class name, condition (null when none is given), and the declaration as messages print it
     */
    public function __construct(private readonly array $declared)
    {
    }

    /**
     * The condition under which throwing an instance of exactly `$thrown` is allowed.
     *
     * Each `#[Throws(D::class, when: ...)]` means "throws a D if and only if the
     * condition holds". So a `$thrown` that is a D needs the condition of every such
     * D, and one that is not a D needs the condition of every other D to be false:
     * where it holds, a D must be thrown instead. A declaration without a condition
     * allows its class anywhere and asks nothing of the others. Nothing is allowed
     * when no declaration covers `$thrown`.
     */
    public function allowed(ClassReflection $thrown): Term
    {
        $covered = false;
        $conditions = [];
        foreach ($this->declared as [$class, $when]) {
            if ($this->covers($class, $thrown)) {
                $covered = true;
                if ($when !== null) {
                    $conditions[] = $when;
                }
            } elseif ($when !== null) {
                $conditions[] = Smt::not($when);
            }
        }

        return $covered ? Smt::all(...$conditions) : Smt::bool(false);
    }

    /**
     * The condition of allowed() in words, such as `#[Throws(A::class, when: '$x < 10')]
     * holds and #[Throws(B::class, when: '$x < 0')] does not hold`, or null when no
     * declaration covers `$thrown`.
     */
    public function describeAllowed(ClassReflection $thrown): ?string
    {
        $covered = false;
        $holds = [];
        $doesNotHold = [];
        foreach ($this->declared as [$class, $when, $label]) {
            if ($this->covers($class, $thrown)) {
                $covered = true;
                if ($when !== null) {
                    $holds[] = $label . ' holds';
                }
            } elseif ($when !== null) {
                $doesNotHold[] = $label . ' does not hold';
            }
        }

        return $covered ? implode(' and ', [...$holds, ...$doesNotHold]) : null;
    }

    private function covers(string $class, ClassReflection $thrown): bool
    {
        return $thrown->getName() === $class || in_array($class, $thrown->getParentClassesNames(), true) || $thrown->implementsInterface($class);
    }

    /**
     * A class named by a `#[Throws]` with a `when` condition that does not cover `$thrown`,
     * but covers some subclass of it: an interface, or a subclass.
     *
     * When the exact class of the thrown object is not known, only that it is a `$thrown`
     * or a subclass, allowed() decides for `$thrown` alone. That would be unsound if a
     * subclass instance fell under another condition.
     */
    public function conditionOnASubclassOf(ClassReflection $thrown, ReflectionProvider $reflection): ?string
    {
        foreach ($this->declared as [$class, $when]) {
            if ($when === null || $this->covers($class, $thrown) || !$reflection->hasClass($class)) {
                continue;
            }

            $declared = $reflection->getClass($class);
            if ($declared->isInterface() || $declared->isSubclassOfClass($thrown)) {
                return $declared->getDisplayName();
            }
        }

        return null;
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
