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
                'throwsOutsideTheCondition' => ['contracts.unexpectedThrow: can be reached where no #[Throws] allows it. Counterexample: $webId = 0.'],
                'anUndeclaredThrowThatIsReachable' => ['contracts.unexpectedThrow: Counterexample: $n = '],
                'neverThrows' => ['contracts.throwsViolated: Counterexample: $n = -'],

                'throwInsideAnOperation' => ['contracts.unsupported: throw is only supported as a statement, or as a whole branch of match, ?: or ?? whose value is returned or assigned.'],
                'throwInACondition' => ['contracts.unsupported: throw is only supported as a statement'],
                'throwsSomethingBuiltElsewhere' => ['contracts.unsupported: Only `throw new Exception(...)` is supported'],
                'anExceptionWithItsOwnConstructor' => ['contracts.unsupported: Cannot throw PhpstanContracts\Tests\Fixtures\ValidatingException: its constructor is not the one of Exception or Error'],
                'anExceptionWithoutAConstructor' => [],
                'aMessageThatIsNotAString' => ['contracts.unsupported: The message of an exception must be a string, not int.'],
                'alwaysThrows' => ['contracts.unsupported: This expression always throws; write a throw statement.'],
                'declaresSomethingThatIsNotThrowable' => ['contracts.invalidContract: PhpstanContracts\Tests\Fixtures\Point is not a Throwable class.'],
                'callsAMethodThatThrows' => ['contracts.unsupported: The call to PhpstanContracts\Tests\Fixtures\ThrowsFixture::guarded() cannot be verified: throw is not supported in a called method yet'],

                'guarded' => [],
                'exception' => [],
            ],
        ]);
    }
}
