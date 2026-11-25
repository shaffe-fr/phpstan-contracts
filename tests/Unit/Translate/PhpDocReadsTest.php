<?php

declare(strict_types=1);

namespace PhpstanContracts\Tests\Unit\Translate;

use PhpstanContracts\Tests\Fixtures\Lookups\LookupAliases;
use PhpstanContracts\Tests\Fixtures\Lookups\LookupBase;
use PhpstanContracts\Tests\Fixtures\Lookups\LookupCeiling;
use PhpstanContracts\Tests\Fixtures\Lookups\LookupChild;
use PhpstanContracts\Tests\Fixtures\Lookups\LookupFlags;
use PhpstanContracts\Tests\Fixtures\Lookups\LookupLimits;
use PhpstanContracts\Tests\Fixtures\Lookups\LookupMarker;
use PhpstanContracts\Tests\Fixtures\Lookups\LookupRanged;
use PhpstanContracts\Tests\Fixtures\Lookups\LookupRate;
use PhpstanContracts\Tests\Fixtures\Lookups\LookupShares;
use PhpstanContracts\Tests\Fixtures\Lookups\LookupStamps;
use PhpstanContracts\Translate\Lookups;
use PhpstanContracts\Translate\PhpDocReads;
use PhpstanContracts\Translate\SourceFiles;
use PHPStan\PhpDocParser\Lexer\Lexer;
use PHPStan\PhpDocParser\Parser\PhpDocParser;
use PHPStan\Testing\PHPStanTestCase;
use ReflectionClass;

/**
 * PHPStan folds the constants of a PHPDoc type, so the files declaring them are only
 * recorded if the PHPDoc is read again.
 */
final class PhpDocReadsTest extends PHPStanTestCase
{
    private Lookups $lookups;

    private PhpDocReads $phpDocs;

    protected function setUp(): void
    {
        parent::setUp();
        $this->lookups = new Lookups(self::createReflectionProvider());
        $this->phpDocs = new PhpDocReads(
            $this->lookups,
            new SourceFiles($this->lookups),
            self::getContainer()->getByType(Lexer::class),
            self::getContainer()->getByType(PhpDocParser::class),
        );
    }

    public function testTheConstantsNamedInPhpDocTypesRecordTheirFiles(): void
    {
        $this->phpDocs->record(self::createReflectionProvider()->getClass(LookupRanged::class));

        $dependencies = $this->lookups->drain();
        self::assertEqualsCanonicalizing(
            [
                ...self::filesOf(
                    LookupRanged::class,
                    // int<0, Limits::MAX>, through a `use` alias
                    LookupLimits::class,
                    // LookupFlags::FLAG_*
                    LookupFlags::class,
                    // T::CODE, T bound to a final class
                    LookupRate::class,
                    // the imported alias Share and the constant it names
                    LookupShares::class,
                    LookupCeiling::class,
                ),
                // SEVEN, through `use const`
                (string) realpath(dirname(__DIR__, 2) . '/Fixtures/GlobalConstants.php'),
            ],
            $dependencies->files,
        );
        self::assertContains('constant:PhpstanContracts\Tests\Fixtures\SEVEN', $dependencies->symbols);
    }

    public function testTypeAliasImportsRecordTheClassTheyComeFrom(): void
    {
        $this->phpDocs->record(self::createReflectionProvider()->getClass(LookupChild::class));

        self::assertEqualsCanonicalizing(
            self::filesOf(LookupChild::class, LookupBase::class, LookupMarker::class, LookupStamps::class, LookupAliases::class),
            $this->lookups->drain()->files,
        );
    }

    public function testASecondClassOfTheSameFilesReplaysWhatTheFirstRead(): void
    {
        $class = self::createReflectionProvider()->getClass(LookupRanged::class);
        $this->phpDocs->record($class);
        $first = $this->lookups->drain();

        $this->phpDocs->record($class);

        self::assertEquals($first, $this->lookups->drain());
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
