<?php

declare(strict_types=1);

namespace PhpstanContracts\Tests\Unit\Translate;

use PhpstanContracts\Exception\VerificationRejected;
use PhpstanContracts\Tests\Fixtures\Lookups\LookupAliases;
use PhpstanContracts\Tests\Fixtures\Lookups\LookupBacked;
use PhpstanContracts\Tests\Fixtures\Lookups\LookupBase;
use PhpstanContracts\Tests\Fixtures\Lookups\LookupCeiling;
use PhpstanContracts\Tests\Fixtures\Lookups\LookupChained;
use PhpstanContracts\Tests\Fixtures\Lookups\LookupChild;
use PhpstanContracts\Tests\Fixtures\Lookups\LookupConfigured;
use PhpstanContracts\Tests\Fixtures\Lookups\LookupDeepCase;
use PhpstanContracts\Tests\Fixtures\Lookups\LookupDeepChain;
use PhpstanContracts\Tests\Fixtures\Lookups\LookupDeepSelf;
use PhpstanContracts\Tests\Fixtures\Lookups\LookupFlags;
use PhpstanContracts\Tests\Fixtures\Lookups\LookupFolded;
use PhpstanContracts\Tests\Fixtures\Lookups\LookupForms;
use PhpstanContracts\Tests\Fixtures\Lookups\LookupLevel;
use PhpstanContracts\Tests\Fixtures\Lookups\LookupLimits;
use PhpstanContracts\Tests\Fixtures\Lookups\LookupMagic;
use PhpstanContracts\Tests\Fixtures\Lookups\LookupMarker;
use PhpstanContracts\Tests\Fixtures\Lookups\LookupMixed;
use PhpstanContracts\Tests\Fixtures\Lookups\LookupNamed;
use PhpstanContracts\Tests\Fixtures\Lookups\LookupRanged;
use PhpstanContracts\Tests\Fixtures\Lookups\LookupRate;
use PhpstanContracts\Tests\Fixtures\Lookups\LookupResult;
use PhpstanContracts\Tests\Fixtures\Lookups\LookupShares;
use PhpstanContracts\Tests\Fixtures\Lookups\LookupStamps;
use PhpstanContracts\Translate\ConstantGuard;
use PhpstanContracts\Translate\Lookups;
use PhpstanContracts\Translate\PhpDocReads;
use PhpstanContracts\Translate\SourceFiles;
use PHPStan\Analyser\NameScope;
use PHPStan\PhpDoc\TypeNodeResolverExtension;
use PHPStan\PhpDocParser\Ast\Type\IdentifierTypeNode;
use PHPStan\PhpDocParser\Ast\Type\TypeNode;
use PHPStan\PhpDocParser\Lexer\Lexer;
use PHPStan\PhpDocParser\Parser\PhpDocParser;
use PHPStan\Reflection\InitializerExprTypeResolver;
use PHPStan\Testing\PHPStanTestCase;
use PHPStan\Type\IntegerType;
use PHPStan\Type\Type;
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
        $this->phpDocs = $this->phpDocReads();
    }

    /**
     * @param array<string, string> $typeAliases
     * @param list<string> $stubFiles
     * @param list<TypeNodeResolverExtension> $typeExtensions
     */
    private function phpDocReads(array $typeAliases = [], array $stubFiles = [], array $typeExtensions = []): PhpDocReads
    {
        $sources = new SourceFiles($this->lookups);

        return new PhpDocReads(
            $this->lookups,
            $sources,
            new ConstantGuard($this->lookups, self::getContainer()->getByType(InitializerExprTypeResolver::class), $sources),
            self::getContainer()->getByType(Lexer::class),
            self::getContainer()->getByType(PhpDocParser::class),
            $typeAliases,
            $stubFiles,
            $typeExtensions,
        );
    }

    public function testTheConstantsNamedInPhpDocTypesRecordTheirFiles(): void
    {
        $this->phpDocs->record(self::createReflectionProvider()->getClass(LookupRanged::class), 1);

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
        $this->phpDocs->record(self::createReflectionProvider()->getClass(LookupChild::class), 1);

        self::assertEqualsCanonicalizing(
            self::filesOf(LookupChild::class, LookupBase::class, LookupMarker::class, LookupStamps::class, LookupAliases::class),
            $this->lookups->drain()->files,
        );
    }

    public function testEveryClassNamedInAPhpDocTypeRecordsItsFile(): void
    {
        $this->phpDocs->record(self::createReflectionProvider()->getClass(LookupForms::class), 1);

        self::assertEqualsCanonicalizing(
            // value-of<>, @mixin, @method, class-string<> and a conditional return type
            self::filesOf(LookupForms::class, LookupLevel::class, LookupMixed::class, LookupMagic::class, LookupNamed::class, LookupResult::class),
            $this->lookups->drain()->files,
        );
    }

    public function testTheInitializersOfTheConstantsAndCasesNamedAreFollowed(): void
    {
        $this->phpDocs->record(self::createReflectionProvider()->getClass(LookupFolded::class), 1);

        self::assertEqualsCanonicalizing(
            // self::OWN, LookupChained::MAX and the case of LookupBacked read a constant of a third file
            self::filesOf(LookupFolded::class, LookupDeepSelf::class, LookupChained::class, LookupDeepChain::class, LookupBacked::class, LookupDeepCase::class),
            $this->lookups->drain()->files,
        );
    }

    public function testTheConfiguredTypeAliasesRecordWhatTheyName(): void
    {
        $phpDocs = $this->phpDocReads(['Capped' => 'int<0, \\' . LookupConfigured::class . '::MAX>']);

        $phpDocs->record(self::createReflectionProvider()->getClass(LookupMixed::class), 1);
        $first = $this->lookups->drain();
        $phpDocs->record(self::createReflectionProvider()->getClass(LookupMixed::class), 1);

        self::assertEqualsCanonicalizing(self::filesOf(LookupMixed::class, LookupConfigured::class), $first->files);
        self::assertEquals($first, $this->lookups->drain(), 'replayed on the next class');
    }

    public function testTheStubFilesRecordWhatTheyName(): void
    {
        $stub = (string) realpath(dirname(__DIR__, 2) . '/Fixtures/Lookups/LookupStubbed.stub');

        $this->phpDocReads([], [$stub])->record(self::createReflectionProvider()->getClass(LookupMixed::class), 1);

        self::assertEqualsCanonicalizing([...self::filesOf(LookupMixed::class, LookupCeiling::class), $stub], $this->lookups->drain()->files);
    }

    public function testATypeResolvedByAnotherExtensionIsRejected(): void
    {
        $extension = new class () implements TypeNodeResolverExtension {
            public function resolve(TypeNode $typeNode, NameScope $nameScope): ?Type
            {
                return $typeNode instanceof IdentifierTypeNode && $typeNode->name === 'LookupMagic' ? new IntegerType() : null;
            }
        };
        $phpDocs = $this->phpDocReads([], [], [$extension]);

        try {
            $phpDocs->record(self::createReflectionProvider()->getClass(LookupForms::class), 3);
            self::fail('The type of the extension is rejected.');
        } catch (VerificationRejected $rejected) {
            self::assertSame(3, $rejected->errorLine);
            self::assertStringStartsWith(
                sprintf('The PHPDoc type LookupMagic in %s on line 7 is resolved by the PHPStan extension %s', realpath((string) (new ReflectionClass(LookupForms::class))->getFileName()), $extension::class),
                $rejected->getMessage(),
            );
        }
        self::assertContains((string) realpath((string) (new ReflectionClass(LookupForms::class))->getFileName()), $this->lookups->drain()->files, 'the file of the refusal is recorded');
    }

    public function testASecondClassOfTheSameFilesReplaysWhatTheFirstRead(): void
    {
        $class = self::createReflectionProvider()->getClass(LookupRanged::class);
        $this->phpDocs->record($class, 1);
        $first = $this->lookups->drain();

        $this->phpDocs->record($class, 1);

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
