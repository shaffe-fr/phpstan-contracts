<?php

declare(strict_types=1);

namespace PhpstanContracts\Tests\Rule;

use PhpstanContracts\Tests\Fixtures\Shadowing\ShadowedConstants;
use PhpstanContracts\Tests\Fixtures\Shadowing\ShadowedFunctions;
use PhpstanContracts\Tests\Support\RuleFixtureTestCase;

/**
 * An unqualified call or constant in a namespace is the function or constant of that
 * namespace when there is one, so a supported built-in is only translated when it is
 * the one PHP uses.
 */
final class ShadowedFunctionRuleTest extends RuleFixtureTestCase
{
    public function testAFunctionOfTheNamespaceIsNotTheBuiltIn(): void
    {
        $this->assertOutcomes([
            ShadowedFunctions::class => [
                'absOfTheNamespace' => ['contracts.unsupported: Call to PhpstanContracts\Tests\Fixtures\Shadowing\abs() is not part of the verified subset: purity cannot be established.'],
                'strlenOfTheNamespace' => ['contracts.unsupported: Call to PhpstanContracts\Tests\Fixtures\Shadowing\strlen() is not part of the verified subset: purity cannot be established.'],
                'maxOfTheNamespace' => ['contracts.unsupported: Call to PhpstanContracts\Tests\Fixtures\Shadowing\max() is not part of the verified subset: purity cannot be established.'],
                'strContainsOfTheNamespace' => ['contracts.unsupported: Call to PhpstanContracts\Tests\Fixtures\Shadowing\str_contains() is not part of the verified subset: purity cannot be established.'],
                'floorOfTheNamespace' => ['contracts.unsupported: Call to PhpstanContracts\Tests\Fixtures\Shadowing\floor() is not part of the verified subset: purity cannot be established.'],
                'isNullOfTheNamespace' => ['contracts.unsupported: Call to PhpstanContracts\Tests\Fixtures\Shadowing\is_null() is not part of the verified subset: purity cannot be established.'],
                'absOfTheNamespaceInAContract' => ['contracts.unsupported: Call to PhpstanContracts\Tests\Fixtures\Shadowing\abs() is not part of the verified subset: purity cannot be established.'],
                'theBuiltInAbs' => [],
                'aBuiltInThatTheNamespaceDoesNotDeclare' => [],
            ],
        ]);
    }

    public function testAConstantOfTheNamespaceIsNotTheBuiltIn(): void
    {
        $this->assertOutcomes([
            ShadowedConstants::class => [
                'phpIntMaxOfTheNamespace' => ['contracts.unsupported: Constant PhpstanContracts\Tests\Fixtures\Shadowing\PHP_INT_MAX is not part of the verified subset: PHP reads it instead of the built-in constant.'],
                'nanOfTheNamespace' => ['contracts.unsupported: Constant PhpstanContracts\Tests\Fixtures\Shadowing\NAN is not part of the verified subset: PHP reads it instead of the built-in constant.'],
                'phpIntMaxOfTheNamespaceInAContract' => ['contracts.unsupported: Constant PhpstanContracts\Tests\Fixtures\Shadowing\PHP_INT_MAX is not part of the verified subset: PHP reads it instead of the built-in constant.'],
                'theBuiltInPhpIntMax' => [],
                'aBuiltInThatTheNamespaceDoesNotDeclare' => [],
                'aBuiltInWithTheWrongCase' => ['contracts.unsupported: Constant php_int_max is not part of the verified subset.'],
            ],
        ]);
    }
}
