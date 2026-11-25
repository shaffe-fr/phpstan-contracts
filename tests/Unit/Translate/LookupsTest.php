<?php

declare(strict_types=1);

namespace PhpstanContracts\Tests\Unit\Translate;

use PhpstanContracts\Tests\Fixtures\Lookups\LookupBase;
use PhpstanContracts\Tests\Fixtures\Lookups\LookupChild;
use PhpstanContracts\Tests\Fixtures\Lookups\LookupMarker;
use PhpstanContracts\Tests\Fixtures\Lookups\LookupStamps;
use PhpstanContracts\Translate\Dependencies;
use PhpstanContracts\Translate\Lookups;
use PhpParser\Node\Name;
use PHPStan\Testing\PHPStanTestCase;
use ReflectionClass;
use RuntimeException;

final class LookupsTest extends PHPStanTestCase
{
    private Lookups $lookups;

    protected function setUp(): void
    {
        parent::setUp();
        $this->lookups = new Lookups(self::createReflectionProvider());
    }

    public function testAClassRecordsItsFileAndTheFilesOfItsParentInterfaceAndTrait(): void
    {
        self::assertTrue($this->lookups->hasClass(LookupChild::class));

        self::assertEqualsCanonicalizing(
            self::filesOf(LookupChild::class, LookupBase::class, LookupMarker::class, LookupStamps::class),
            $this->lookups->drain()->files,
        );
    }

    public function testABuiltInClassRecordsNothing(): void
    {
        $this->lookups->getClass(\InvalidArgumentException::class);

        self::assertTrue($this->lookups->drain()->isEmpty());
    }

    public function testAMissingClassIsRecordedAsMissing(): void
    {
        self::assertFalse($this->lookups->hasClass('PhpstanContracts\Tests\Fixtures\Lookups\Nowhere'));

        $dependencies = $this->lookups->drain();
        self::assertSame(['PhpstanContracts\Tests\Fixtures\Lookups\Nowhere'], $dependencies->missingClasses);
        self::assertSame([], $dependencies->files);
    }

    public function testFunctionsAndConstantsAreRecordedAsSymbols(): void
    {
        $this->lookups->hasFunction(new Name\FullyQualified('App\Abs'), null);
        $this->lookups->hasConstant(new Name\FullyQualified('App\LIMIT'), null);

        self::assertSame(['function:app\abs', 'constant:App\LIMIT'], $this->lookups->drain()->symbols);
    }

    public function testACapturedSetReplaysWhatTheFirstLookupRecorded(): void
    {
        [$found, $captured] = $this->lookups->capture(fn (): bool => $this->lookups->hasClass(LookupChild::class));
        self::assertTrue($found);
        $first = $this->lookups->drain();

        $this->lookups->replay($captured);

        self::assertEquals($first, $this->lookups->drain());
        self::assertEqualsCanonicalizing($first->files, $captured->files);
    }

    public function testACaptureThatThrowsStillRecordsWhatItRead(): void
    {
        try {
            $this->lookups->capture(function (): never {
                $this->lookups->hasClass(LookupBase::class);
                throw new RuntimeException('rejected');
            });
        } catch (RuntimeException) {
        }

        self::assertSame(self::filesOf(LookupBase::class), $this->lookups->drain()->files);
    }

    public function testDrainEmptiesTheRecorder(): void
    {
        $this->lookups->hasClass(LookupChild::class);
        $this->lookups->hasClass('Nowhere');
        $this->lookups->drain();

        self::assertTrue($this->lookups->drain()->isEmpty());
    }

    public function testFilesOtherThanLeavesOutTheAnalysedFile(): void
    {
        [$child, $base] = self::filesOf(LookupChild::class, LookupBase::class);
        $dependencies = new Dependencies([$child, $base]);

        self::assertSame([$base], $dependencies->filesOtherThan($child));
        if (PHP_OS_FAMILY === 'Windows') {
            self::assertSame([$base], $dependencies->filesOtherThan(strtoupper($child)));
        }
    }

    /**
     * @param class-string ...$classes
     * @return list<string>
     */
    private static function filesOf(string ...$classes): array
    {
        return array_values(array_map(static fn (string $class): string => (string) realpath((string) (new ReflectionClass($class))->getFileName()), $classes));
    }
}
