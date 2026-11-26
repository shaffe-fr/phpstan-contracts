<?php

declare(strict_types=1);

namespace PhpstanContracts\Tests\Rule;

use PhpstanContracts\Tests\Fixtures\CrossFile\Busy;
use PhpstanContracts\Tests\Fixtures\CrossFile\Chain;
use PhpstanContracts\Tests\Fixtures\CrossFile\CrossFileFixture;
use PhpstanContracts\Tests\Fixtures\CrossFile\CrossFileReplay;
use PhpstanContracts\Tests\Fixtures\CrossFile\Once;
use PhpstanContracts\Tests\Fixtures\CrossFile\OpenRate;
use PhpstanContracts\Tests\Fixtures\CrossFile\ReceiptBase;
use PhpstanContracts\Tests\Fixtures\CrossFile\Stamped;
use PhpstanContracts\Tests\Fixtures\CrossFile\Steps;
use PhpstanContracts\Tests\Fixtures\CrossFile\Tariff;
use PhpstanContracts\Tests\Fixtures\CrossFile\TariffNonStrict;
use PhpstanContracts\Tests\Fixtures\CrossFile\Twice;
use PhpstanContracts\Tests\Fixtures\CrossFile\TwiceBase;
use PhpstanContracts\Tests\Fixtures\CrossFile\TwiceData;
use PhpstanContracts\Tests\Fixtures\CrossFile\TwiceDataBase;
use PhpstanContracts\Tests\Fixtures\CrossFile\TwiceDataKid;
use PhpstanContracts\Tests\Fixtures\CrossFile\TwiceKid;
use PhpstanContracts\Tests\Fixtures\CrossFile\TwiceLeaf;
use PhpstanContracts\Tests\Fixtures\CrossFile\TwiceMiddle;
use PhpstanContracts\Tests\Fixtures\CrossFile\TwiceTop;
use PhpstanContracts\Tests\Support\RuleFixtureTestCase;

final class CrossFileRuleTest extends RuleFixtureTestCase
{
    public function testCallsAndNewAcrossFilesAreTranslatedFromTheBodyOfTheCallee(): void
    {
        $this->assertOutcomes([
            CrossFileFixture::class => [
                'provenCall' => [],
                'refutedCall' => ['contracts.ensuresViolated: Postcondition #[Ensures(\'$result === $cents\')] can be violated. Counterexample: $tariff = Tariff(baseCents: '],
                'violatesThePreconditionOfTheCallee' => ['contracts.requiresViolated: The call to PhpstanContracts\Tests\Fixtures\CrossFile\Tariff::withSurcharge() can violate its precondition #[Requires(\'$cents >= 0 && $cents <= 1000000\')]. Counterexample: $tariff = Tariff('],
                'overflowsInTheCallee' => ['contracts.overflow: `$this->baseCents * $factor` can leave the PHP int range, in the call to PhpstanContracts\Tests\Fixtures\CrossFile\Tariff::scaled(). Counterexample: $tariff = Tariff('],
                'chainOverThreeFiles' => [],
                'chainOverThreeFilesBroken' => ['contracts.ensuresViolated: Postcondition #[Ensures(\'$result <= 1000000\')] can be violated. Counterexample: $cents = '],
                'buildsAClassOfAnotherFile' => [],
                'buildsAClassWhoseConstructorHasLogic' => ['contracts.unsupported: Cannot instantiate Busy: its constructor contains logic, which is not verified.'],
                'buildsAClassWhoseParentHasADestructor' => ['contracts.unsupported: Cannot instantiate Stamped: its destructor runs code that is not verified.'],
                'callsAMethodThatCanBeOverridden' => ['contracts.unsupported: PhpstanContracts\Tests\Fixtures\CrossFile\OpenRate::percent() can be overridden: PhpstanContracts\Tests\Fixtures\CrossFile\OpenRate is not final, so a subclass can replace the method. Make the class or the method final, or the method private.'],
                'callsANonStrictFile' => [],
                'aNonStrictCalleeCannotRelyOnCoercion' => ['contracts.unsupported: The call to PhpstanContracts\Tests\Fixtures\CrossFile\TariffNonStrict::clampOf() cannot be verified: Argument $cents of PhpstanContracts\Tests\Fixtures\CrossFile\Tariff::clamp() is float, but the parameter is int.'],
                'aChainDeeperThanTheLimit' => ['contracts.unsupported: The call to PhpstanContracts\Tests\Fixtures\CrossFile\Chain::depth1() cannot be verified: the chain of calls PhpstanContracts\Tests\Fixtures\CrossFile\CrossFileFixture::aChainDeeperThanTheLimit() -> PhpstanContracts\Tests\Fixtures\CrossFile\Chain::depth1() -> PhpstanContracts\Tests\Fixtures\CrossFile\Chain::depth2() -> PhpstanContracts\Tests\Fixtures\CrossFile\Chain::depth3() -> PhpstanContracts\Tests\Fixtures\CrossFile\Chain::depth4() -> PhpstanContracts\Tests\Fixtures\CrossFile\Chain::depth5() -> PhpstanContracts\Tests\Fixtures\CrossFile\Chain::depth6() -> PhpstanContracts\Tests\Fixtures\CrossFile\Chain::depth7() -> PhpstanContracts\Tests\Fixtures\CrossFile\Chain::depth8() -> PhpstanContracts\Tests\Fixtures\CrossFile\Chain::depth9() -> PhpstanContracts\Tests\Fixtures\CrossFile\Chain::depth10() -> PhpstanContracts\Tests\Fixtures\CrossFile\Chain::depth11() -> PhpstanContracts\Tests\Fixtures\CrossFile\Chain::depth12() -> PhpstanContracts\Tests\Fixtures\CrossFile\Chain::depth13() -> PhpstanContracts\Tests\Fixtures\CrossFile\Chain::depth14() -> PhpstanContracts\Tests\Fixtures\CrossFile\Chain::depth15() -> PhpstanContracts\Tests\Fixtures\CrossFile\Chain::depth16() -> PhpstanContracts\Tests\Fixtures\CrossFile\Chain::depth17() is deeper than 16 calls, the limit for translating the body of each called method in place. Call a method with a shorter chain of calls, or split the rule into smaller verified methods.'],
                'moreBodiesThanTheLimit' => ['contracts.unsupported: The call to PhpstanContracts\Tests\Fixtures\CrossFile\Chain::level1() cannot be verified: PhpstanContracts\Tests\Fixtures\CrossFile\CrossFileFixture::moreBodiesThanTheLimit() would translate more than 256 bodies of called methods, counting a method once per call that reaches it (this call is reached through PhpstanContracts\Tests\Fixtures\CrossFile\CrossFileFixture::moreBodiesThanTheLimit() -> PhpstanContracts\Tests\Fixtures\CrossFile\Chain::level1() -> PhpstanContracts\Tests\Fixtures\CrossFile\Chain::level2()). Split the rule into smaller verified methods, or call fewer helpers from it.'],
                // PHPStan reflects the first declaration, PHP runs the second: proving `$result === 1` would be false.
                'callsAClassDeclaredTwice' => ['contracts.unsupported: Twice.php, under conditions that only running the file decides, so the body that runs is not known. Call a method of a class declared once.'],
                'buildsAClassDeclaredTwice' => ['contracts.unsupported: Twice.php, under conditions that only running the file decides, so the constructor that runs is not known. Build a class declared once.'],
                'callsThroughASubclassDeclaredTwice' => ['contracts.unsupported: PhpstanContracts\Tests\Fixtures\CrossFile\TwiceBase::value() cannot be verified: PhpstanContracts\Tests\Fixtures\CrossFile\TwiceKid, through which it is called, is declared more than once in '],
                'callsAnInheritedMethodOfADataClassDeclaredTwice' => ['contracts.unsupported: PhpstanContracts\Tests\Fixtures\CrossFile\TwiceDataBase::amount() cannot be verified: PhpstanContracts\Tests\Fixtures\CrossFile\TwiceDataKid, through which it is called, is declared more than once in '],
                'buildsAClassWhoseParentIsDeclaredTwice' => ['contracts.unsupported: Cannot instantiate TwiceLeaf: its parent class TwiceMiddle is declared more than once in '],
                'callsAClassDeclaredOnceInAnIf' => [],
                'buildsAClassDeclaredOnceInAnIf' => [],
            ],
            Twice::class => ['value' => []],
            TwiceData::class => [],
            Once::class => ['value' => []],
            TwiceBase::class => ['value' => []],
            TwiceDataBase::class => ['amount' => []],
            TwiceTop::class => [],
            // Listed as PHP runs them: the second declarations override value() and amount().
            TwiceKid::class => ['value' => []],
            TwiceDataKid::class => ['amount' => []],
            TwiceMiddle::class => [],
            TwiceLeaf::class => [],
            CrossFileReplay::class => [
                'surchargeIsIgnored' => ['contracts.ensuresViolated: Counterexample: $percent = '],
                'surchargeOfAnyAmount' => ['contracts.requiresViolated: The call to PhpstanContracts\Tests\Fixtures\CrossFile\Tariff::withSurcharge() can violate its precondition'],
                'scaledPastTheIntRange' => ['contracts.overflow: in the call to PhpstanContracts\Tests\Fixtures\CrossFile\Tariff::scaled().'],
                'doubledClampOverThreeFiles' => ['contracts.ensuresViolated: => $result = 2000000'],
                'nonStrictSurchargeIsIgnored' => ['contracts.ensuresViolated: Counterexample: $percent = '],
            ],
            Tariff::class => ['withSurcharge' => [], 'scaled' => [], 'clamp' => []],
            TariffNonStrict::class => ['withSurcharge' => [], 'clampOf' => []],
            Steps::class => ['doubledClamp' => []],
            OpenRate::class => ['percent' => []],
            Busy::class => [],
            Stamped::class => [],
            ReceiptBase::class => ['__destruct' => []],
            Chain::class => self::unverified([
                'depth1', 'depth2', 'depth3', 'depth4', 'depth5', 'depth6', 'depth7', 'depth8', 'depth9',
                'depth10', 'depth11', 'depth12', 'depth13', 'depth14', 'depth15', 'depth16', 'depth17',
                'level1', 'level2', 'level3', 'level4', 'level5',
            ]),
        ]);
    }

    /**
     * @param list<string> $methods helpers without a contract, which report nothing themselves
     * @return array<string, list<string>>
     */
    private static function unverified(array $methods): array
    {
        return array_fill_keys($methods, []);
    }
}
