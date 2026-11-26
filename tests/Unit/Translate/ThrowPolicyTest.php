<?php

declare(strict_types=1);

namespace PhpstanContracts\Tests\Unit\Translate;

use PhpstanContracts\Smt\Smt;
use PhpstanContracts\Smt\Sort;
use PhpstanContracts\Smt\Term;
use PhpstanContracts\Translate\ThrowPolicy;
use PHPStan\Reflection\ClassReflection;
use PHPStan\Testing\PHPStanTestCase;

final class ThrowPolicyTest extends PHPStanTestCase
{
    public function testACalledMethodIsHeldToTheDeclarationsOfTheVerifiedMethod(): void
    {
        $when = self::path('when');
        $root = new ThrowPolicy([[\InvalidArgumentException::class, $when, "#[Throws(InvalidArgumentException::class, when: '\$id < 0')]"]]);
        $grandchild = $root->forCall()->forCall();

        self::assertSame($root->allowed(self::reflect(\InvalidArgumentException::class))->smt, $grandchild->allowed(self::reflect(\InvalidArgumentException::class))->smt);
        self::assertSame($when->smt, $grandchild->allowed(self::reflect(\InvalidArgumentException::class))->smt);
        self::assertTrue($grandchild->allowed(self::reflect(\RuntimeException::class))->isFalse());
        self::assertSame(
            "#[Throws(InvalidArgumentException::class, when: '\$id < 0')] holds",
            $grandchild->describeAllowed(self::reflect(\InvalidArgumentException::class)),
        );
        self::assertNull($grandchild->describeAllowed(self::reflect(\RuntimeException::class)));
    }

    public function testTheStatementsOfACalledMethodTakeOnlyItsOwnPaths(): void
    {
        $root = new ThrowPolicy([]);
        $child = $root->forCall();
        $path = self::path('p');

        $child->record($path);

        self::assertSame([], $root->take());
        self::assertSame([$path], $child->take());
        self::assertSame([], $child->take());
    }

    public function testAThrowInACalledMethodIsAThrowOfEveryMethodThatLeadsThere(): void
    {
        $root = new ThrowPolicy([]);
        $child = $root->forCall();
        $sibling = $root->forCall();
        $grandchild = $child->forCall();

        self::assertFalse($root->anyThrow());

        $grandchild->record(self::path('p'));

        self::assertTrue($grandchild->anyThrow());
        self::assertTrue($child->anyThrow());
        self::assertTrue($root->anyThrow());
        self::assertFalse($sibling->anyThrow());
    }

    public function testThrownIsEveryPathRecordedEvenOnceTaken(): void
    {
        $policy = (new ThrowPolicy([]))->forCall();
        self::assertTrue($policy->thrown()->isFalse());

        $first = self::path('p');
        $second = self::path('q');
        $policy->record($first);
        $policy->take();
        $policy->record($second);

        self::assertSame(Smt::any($first, $second)->smt, $policy->thrown()->smt);
    }

    private static function path(string $name): Term
    {
        return Smt::symbol($name, Sort::bool());
    }

    private static function reflect(string $class): ClassReflection
    {
        return self::createReflectionProvider()->getClass($class);
    }
}
