<?php

declare(strict_types=1);

namespace PhpstanContracts\Tests\Unit\Translate;

use PhpstanContracts\Tests\Fixtures\Lookups\LookupCode;
use PhpstanContracts\Tests\Fixtures\Lookups\LookupLabel;
use PhpstanContracts\Tests\Fixtures\Lookups\LookupRate;
use PhpstanContracts\Translate\ConstantGuard;
use PhpstanContracts\Translate\EnumRegistry;
use PhpstanContracts\Translate\Lookups;
use PhpstanContracts\Translate\SourceFiles;
use PHPStan\Reflection\InitializerExprTypeResolver;
use PHPStan\Testing\PHPStanTestCase;
use ReflectionClass;

/**
 * A refusal that depends on another file records that file, so that fixing it refreshes the refusal.
 */
final class RefusalDependenciesTest extends PHPStanTestCase
{
    private Lookups $lookups;

    private ConstantGuard $guard;

    protected function setUp(): void
    {
        parent::setUp();
        $this->lookups = new Lookups(self::createReflectionProvider());
        $this->guard = new ConstantGuard(
            $this->lookups,
            self::getContainer()->getByType(InitializerExprTypeResolver::class),
            new SourceFiles($this->lookups),
        );
    }

    public function testAConversionRefusalRecordsTheFileOfTheConstantWhoseTypeCausedIt(): void
    {
        $constant = $this->lookups->getClass(LookupLabel::class)->getConstant('LABEL');
        $this->lookups->drain();

        self::assertSame(
            'An initializer in ' . LookupLabel::class . ' converts a float to a string, whose digits depend on the precision ini setting, which is not modelled.',
            $this->guard->constantRefusal($constant),
        );
        self::assertSame([self::fileOf(LookupRate::class)], $this->lookups->drain()->files);
    }

    public function testAnUnfoldableBackingValueRecordsTheMissingClassItReads(): void
    {
        $enum = (new EnumRegistry($this->lookups, $this->guard))->find(LookupCode::class);

        self::assertNotNull($enum);
        self::assertSame(
            'The backing value of ' . LookupCode::class . '::A cannot be folded to a literal; write it as a literal.',
            $enum->unmodelledBacking,
        );
        $dependencies = $this->lookups->drain();
        self::assertSame(['PhpstanContracts\Tests\Fixtures\Lookups\Nowhere'], $dependencies->missingClasses);
        self::assertContains(self::fileOf(LookupRate::class), $dependencies->files);
    }

    /**
     * @param class-string $class
     */
    private static function fileOf(string $class): string
    {
        return (string) realpath((string) (new ReflectionClass($class))->getFileName());
    }
}
