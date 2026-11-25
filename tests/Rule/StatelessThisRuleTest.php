<?php

declare(strict_types=1);

namespace PhpstanContracts\Tests\Rule;

use PhpstanContracts\Tests\Fixtures\StatelessChild;
use PhpstanContracts\Tests\Fixtures\StatelessChildOfStatefulParent;
use PhpstanContracts\Tests\Fixtures\StatelessGuard;
use PhpstanContracts\Tests\Fixtures\StatelessOpen;
use PhpstanContracts\Tests\Fixtures\StatelessParentWithState;
use PhpstanContracts\Tests\Fixtures\StatelessUsingTrait;
use PhpstanContracts\Tests\Fixtures\StatelessWithDependency;
use PhpstanContracts\Tests\Fixtures\StatelessWithMutableProperty;
use PhpstanContracts\Tests\Support\RuleFixtureTestCase;

final class StatelessThisRuleTest extends RuleFixtureTestCase
{
    public function testThisIsTheReceiverOfCallsInAClassWithoutInstanceProperties(): void
    {
        $this->assertOutcomes([
            StatelessGuard::class => [
                'withinLimit' => [],
                'clamped' => [],
                'clampedBroken' => ['contracts.ensuresViolated: Counterexample: $amount = '],
                'halved' => [],
                'halvedWithoutPrecondition' => ['contracts.requiresViolated: The call to PhpstanContracts\Tests\Fixtures\StatelessGuard::half() can violate its precondition #[Requires(\'$amount >= 0\')]. Counterexample: $amount = -'],
                'comparesThisToItself' => ['contracts.unsupported: Class PhpstanContracts\Tests\Fixtures\StatelessGuard has no instance property, so $this has no state to model: use it only as the receiver of a call, such as $this->helper(). Comparing it, passing it as an argument and reading a property of it are not supported.'],
                'passesThisAsAnArgument' => ['contracts.unsupported: PhpstanContracts\Tests\Fixtures\StatelessGuard has no properties to model.'],
                'readsAProperty' => ['contracts.unsupported: Class PhpstanContracts\Tests\Fixtures\StatelessGuard has no instance property, so $this has no state to model'],
                'measure' => [],
                'isUnder' => [],
                'floored' => [],
                'capped' => [],
                'half' => [],
            ],
            StatelessChild::class => [
                'quadrupledPlusOne' => [],
                'twice' => [],
            ],
        ]);
    }

    public function testAClassThatIsNotFinalOnlyCallsFinalAndPrivateMethods(): void
    {
        $this->assertOutcomes([
            StatelessOpen::class => [
                'callsAPrivateMethod' => [],
                'callsAFinalMethod' => [],
                'callsAMethodThatCanBeOverridden' => ['contracts.unsupported: PhpstanContracts\Tests\Fixtures\StatelessOpen::overridable() can be overridden: PhpstanContracts\Tests\Fixtures\StatelessOpen is not final'],
                'next' => [],
                'afterNext' => [],
                'overridable' => [],
            ],
        ]);
    }

    public function testAnInstancePropertyOfAnyKindIsState(): void
    {
        $this->assertOutcomes([
            StatelessWithDependency::class => [
                'callsAHelper' => ['contracts.unsupported: Object state ($this) is only part of the verified subset in enums, readonly data classes and classes without instance properties. Class PhpstanContracts\Tests\Fixtures\StatelessWithDependency has the instance property $rate, so $this has state. It cannot be modelled as data: Type PhpstanContracts\Tests\Fixtures\StatelessWithDependency is not supported: only enums, readonly data classes, lists, array shapes with string keys and scalars are. Property PhpstanContracts\Tests\Fixtures\StatelessWithDependency::$rate must be public and readonly'],
                'helper' => [],
            ],
            StatelessWithMutableProperty::class => [
                'callsAHelper' => ['contracts.unsupported: Object state ($this) is only part of the verified subset in enums, readonly data classes and classes without instance properties. Class PhpstanContracts\Tests\Fixtures\StatelessWithMutableProperty has the instance property $count, so $this has state. It cannot be modelled as data: Type PhpstanContracts\Tests\Fixtures\StatelessWithMutableProperty is not supported: only enums, readonly data classes, lists, array shapes with string keys and scalars are. Property PhpstanContracts\Tests\Fixtures\StatelessWithMutableProperty::$count must be public and readonly'],
                'helper' => [],
            ],
            StatelessParentWithState::class => [
                'hidden' => [],
            ],
            StatelessChildOfStatefulParent::class => [
                'callsAHelper' => ['contracts.unsupported: Object state ($this) is only part of the verified subset in enums, readonly data classes and classes without instance properties. Class PhpstanContracts\Tests\Fixtures\StatelessChildOfStatefulParent has the instance property $hidden, so $this has state. It cannot be modelled as data: Type PhpstanContracts\Tests\Fixtures\StatelessChildOfStatefulParent is not supported: only enums, readonly data classes, lists, array shapes with string keys and scalars are. PhpstanContracts\Tests\Fixtures\StatelessChildOfStatefulParent has no properties to model.'],
                'helper' => [],
            ],
            StatelessUsingTrait::class => [
                'callsAHelper' => ['contracts.unsupported: Object state ($this) is only part of the verified subset in enums, readonly data classes and classes without instance properties. Class PhpstanContracts\Tests\Fixtures\StatelessUsingTrait has the instance property $fromTrait, so $this has state. It cannot be modelled as data: Type PhpstanContracts\Tests\Fixtures\StatelessUsingTrait is not supported: only enums, readonly data classes, lists, array shapes with string keys and scalars are. Property PhpstanContracts\Tests\Fixtures\StatelessUsingTrait::$fromTrait must be public and readonly'],
                'helper' => [],
            ],
        ]);
    }
}
