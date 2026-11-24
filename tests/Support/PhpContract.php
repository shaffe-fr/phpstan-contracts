<?php
declare(strict_types=1);
namespace PhpstanContracts\Tests\Support;
use Closure;
use LogicException;
use ReflectionMethod;
/**
 * Evaluates the expression of a `#[Requires]`, `#[Ensures]` or `#[Throws(when:)]` in PHP, which
 * is the reference the proofs are compared with.
 */
final class PhpContract
{
    /**
     * The contract is evaluated in the scope of the class, so that `self::` resolves,
     * with `$this` passed as an ordinary parameter.
     *
     * @param list<mixed> $arguments
     */
    public static function evaluate(string $contract, ReflectionMethod $method, ?object $object, array $arguments, mixed $result): bool
    {
        if (!function_exists('implies')) {
            eval('function implies(bool $premise, bool $conclusion): bool { return !$premise || $conclusion; }');
        }
        $names = array_map(static fn ($parameter): string => '$' . $parameter->getName(), $method->getParameters());
        $closure = eval(sprintf(
            'namespace PhpstanContracts\Tests\Fixtures; return static function (%s): bool { return (bool) (%s); };',
            implode(', ', ['$self', ...$names, '$result']),
            str_replace('$this', '$self', $contract),
        ));
        $closure = Closure::bind($closure, null, $method->getDeclaringClass()->getName());
        if ($closure === null) {
            throw new LogicException('Cannot bind the contract to ' . $method->getDeclaringClass()->getName());
        }
        return $closure($object, ...$arguments, ...[$result]);
    }
}
