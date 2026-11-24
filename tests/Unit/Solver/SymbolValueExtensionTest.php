<?php

declare(strict_types=1);

namespace PhpstanContracts\Tests\Unit\Solver;

use PhpstanContracts\Solver\SymbolValueExtension;
use PHPStan\Testing\PHPStanTestCase;

final class SymbolValueExtensionTest extends PHPStanTestCase
{
    private SymbolValueExtension $extension;

    protected function setUp(): void
    {
        parent::setUp();
        $this->extension = new SymbolValueExtension(self::createReflectionProvider());
    }

    public function testAFunctionIsFoundOrMissing(): void
    {
        self::assertSame('found', $this->extension->getValue('function:abs'));
        self::assertSame('missing', $this->extension->getValue('function:phpstancontracts\tests\nowhere'));
    }

    public function testAConstantIsFoundOrMissing(): void
    {
        self::assertSame('found', $this->extension->getValue('constant:PHP_INT_MAX'));
        self::assertSame('found', $this->extension->getValue('constant:PhpstanContracts\Tests\Fixtures\SEVEN'));
        self::assertSame('missing', $this->extension->getValue('constant:PhpstanContracts\Tests\NOWHERE'));
    }

    public function testAnUnknownKeyIsMissing(): void
    {
        self::assertSame('missing', $this->extension->getValue('class:abs'));
        self::assertSame('missing', $this->extension->getValue('abs'));
    }

    public function testKeysAreStoredUnchanged(): void
    {
        self::assertSame('function:app\abs', $this->extension->keyToResultCache('function:app\abs'));
        self::assertSame('constant:App\LIMIT', $this->extension->keyFromResultCache('constant:App\LIMIT'));
    }
}
