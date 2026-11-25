<?php

declare(strict_types=1);

namespace PhpstanContracts\Tests\Rule;

use PhpstanContracts\Tests\Fixtures\ThrowsFixture;
use PhpstanContracts\Tests\Support\RuleFixtureTestCase;

final class ThrowsRuleTest extends RuleFixtureTestCase
{
    public function testAThrowIsOnlyReachableUnderItsDeclaredCondition(): void
    {
        $this->assertOutcomes([
            ThrowsFixture::class => [
                'fromWebId' => [],
                'anUndeclaredThrowThatIsUnreachable' => [],
                'aSubclassOfTheDeclaredClass' => [],
                'withoutACondition' => [],
                'throwOnTheRightOfACoalesce' => [],
                'throwInAnAssignment' => [],

                'fromWebIdBroken' => [
                    'contracts.ensuresViolated: Counterexample: $webId = 1000000 => $result = 99000000',
                    'contracts.throwsViolated: #[Throws(InvalidArgumentException::class, when: \'$webId < 0 || $webId >= self::SIZE\')] is violated: the method can return although the condition holds. Counterexample: $webId = 1000000 => $result = 99000000',
                ],
                'throwsOutsideTheCondition' => ['contracts.unexpectedThrow: can be reached where the #[Throws] declarations do not allow it: they allow it only where #[Throws(InvalidArgumentException::class, when: \'$webId < 0 || $webId >= self::SIZE\')] holds. Counterexample: $webId = 0.'],
                'anUndeclaredThrowThatIsReachable' => ['contracts.unexpectedThrow: can be reached where no #[Throws] allows it. Counterexample: $n = '],
                'neverThrows' => ['contracts.throwsViolated: Counterexample: $n = -'],

                'throwInsideAnOperation' => ['contracts.unsupported: throw is only supported as a statement, or as a whole branch of match, ?: or ?? whose value is returned or assigned.'],
                'throwInACondition' => ['contracts.unsupported: throw is only supported as a statement'],
                'anExceptionWithItsOwnConstructor' => ['contracts.unsupported: Cannot throw PhpstanContracts\Tests\Fixtures\ValidatingException: its constructor is not the one of Exception or Error'],
                'anExceptionWithoutAConstructor' => [],
                'aMessageThatIsNotAString' => ['contracts.unsupported: The message of an exception must be a string, not int.'],
                'alwaysThrows' => ['contracts.unsupported: This expression always throws; write a throw statement.'],
                'declaresSomethingThatIsNotThrowable' => ['contracts.invalidContract: PhpstanContracts\Tests\Fixtures\Point is not a Throwable class.'],
                'callsAMethodThatThrows' => ['contracts.unsupported: The call to PhpstanContracts\Tests\Fixtures\ThrowsFixture::guarded() cannot be verified: throw is not supported in a called method yet'],

                'twoDisjointDeclarations' => [],
                'aGeneralAndASpecificDeclaration' => [],
                'aGeneralDeclarationDoesNotWidenASpecificOne' => ['contracts.unexpectedThrow: `throw new \InvalidArgumentException(\'Too large.\')` can be reached where the #[Throws] declarations do not allow it: they allow it only where #[Throws(InvalidArgumentException::class, when: \'$x < 0\')] holds. Counterexample: $x = '],
                'anotherClassThrownUnderACondition' => ['contracts.unexpectedThrow: `throw new \RangeException(\'Small.\')` can be reached where the #[Throws] declarations do not allow it: they allow it only where #[Throws(RangeException::class, when: \'$x < 10\')] holds and #[Throws(InvalidArgumentException::class, when: \'$x < 0\')] does not hold. Counterexample: $x = -'],
                'aNullPreviousException' => [],
                'anError' => [],
                'throwInACoalesceAssignment' => [],
                'aConditionThatReliesOnAPrecondition' => [],
                'declaresAClassThatDoesNotExist' => ['contracts.invalidContract: Cannot resolve class PhpstanContracts\Tests\Fixtures\UnknownException in #[Throws]; write its fully qualified name.'],
                'anUnknownNamedArgument' => ['contracts.unsupported: Invalid arguments for the constructor of LogicException.'],
                'aDuplicateArgument' => ['contracts.unsupported: Invalid arguments for the constructor of LogicException.'],
                'aPreviousExceptionThatIsNotNull' => ['contracts.unsupported: The previous exception can only be null here.'],

                'guarded' => [],
            ],
        ]);
    }
}
