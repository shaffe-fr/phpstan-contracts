<?php

declare(strict_types=1);

namespace PhpstanContracts\Tests\Rule;

use PhpstanContracts\Tests\Fixtures\StatementFixture;
use PhpstanContracts\Tests\Fixtures\TypeFixture;
use PhpstanContracts\Tests\Support\RuleFixtureTestCase;

final class StatementRuleTest extends RuleFixtureTestCase
{
    public function testUnsupportedStatementsAndExpressionsAreRejectedWithAReason(): void
    {
        $this->assertOutcomes([
            StatementFixture::class => [
                'matchWithOnlyADefault' => [],
                'helper' => [],

                'whileLoop' => ['contracts.unsupported: Loops are not supported'],
                'forLoop' => ['contracts.unsupported: Loops are not supported'],
                'doWhileLoop' => ['contracts.unsupported: Loops are not supported'],
                'switchStatement' => ['contracts.unsupported: switch is not supported; use match'],
                'tryCatch' => ['contracts.unsupported: try/catch is not supported'],
                'throwStatement' => ['contracts.unsupported: throw is not supported yet'],
                'increment' => ['contracts.unsupported: Increment and decrement are not supported'],
                'echoStatement' => ['contracts.unsupported: Side effects and global or static state are forbidden'],
                'bareReturn' => ['contracts.unsupported: A bare return is not supported'],
                'variableChangesType' => ['contracts.unsupported: Variable $x changes type from int to bool'],
                'variableDefinedInOneBranchOnly' => ['contracts.unsupported: Variable $x is not defined at this point'],
                'writesAProperty' => ['contracts.unsupported: Only assignments to local variables are allowed'],
                'callsAnotherMethod' => ['contracts.unsupported: Method calls and instantiations are not part of the verified subset'],
                'callsAnUnknownFunction' => ['contracts.unsupported: Call to strtoupper() is not part of the verified subset'],
                'closure' => ['contracts.unsupported: Closures are not supported'],
                'arrayLiteral' => ['contracts.unsupported: Arrays are not supported yet'],
                'castToString' => ['contracts.unsupported: Only (int) and (float) casts are supported'],
                'shortTernary' => ['contracts.unsupported: The short ternary'],
                'power' => ['contracts.unsupported: Operator "**" is not part of the verified subset'],
                'spaceship' => ['contracts.unsupported: Operator "<=>" is not part of the verified subset'],
                'truthiness' => ['contracts.unsupported: implicit truthiness is not supported'],
                'variableVariable' => ['contracts.unsupported: Variable variables ($$name) are forbidden'],
                'instanceOfCheck' => ['contracts.unsupported: is not part of the verified subset'],
                'unknownConstant' => ['contracts.unsupported: Constant PHP_EOL is not part of the verified subset'],
            ],
        ]);
    }

    public function testUnsupportedTypesAreRejectedOnTheParameterOrTheReturnType(): void
    {
        $this->assertOutcomes([
            TypeFixture::class => [
                'everySupportedTypeIsAccepted' => [],

                'arrayParameter' => ['contracts.unsupported: Type list<int> is not supported'],
                'mixedParameter' => ['contracts.unsupported: Type mixed is not supported'],
                'unionOfDifferentTypes' => ['contracts.unsupported: Type int|string is not supported'],
                'intOrFloat' => ['contracts.unsupported: Type float|int is not supported'],
                'objectParameter' => ['contracts.unsupported: Type object is not supported'],
                'callableParameter' => ['contracts.unsupported: Type callable'],
                'closureParameter' => ['contracts.unsupported'],
                'interfaceParameter' => ['contracts.unsupported: only concrete readonly data classes are'],
                'voidReturn' => ['contracts.unsupported: Type void is not supported'],
                'arrayReturn' => ['contracts.unsupported: Type array is not supported'],
                'variadicParameter' => ['contracts.unsupported: Variadic and by-reference parameters are not supported'],
                'byReferenceParameter' => ['contracts.unsupported: Variadic and by-reference parameters are not supported'],
                'parameterNamedResult' => ['contracts.invalidContract: reserved for #[Ensures]'],
            ],
        ]);
    }
}
