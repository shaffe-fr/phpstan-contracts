<?php

declare(strict_types=1);

namespace PhpstanContracts\Tests\Examples;

use PhpstanContracts\Examples\Pricing;
use PhpstanContracts\Examples\RealWorld\Calendar;
use PhpstanContracts\Examples\RealWorld\CancellationPolicy;
use PhpstanContracts\Examples\RealWorld\Checkout;
use PhpstanContracts\Examples\RealWorld\CurrencyParser;
use PhpstanContracts\Examples\RealWorld\Floats;
use PhpstanContracts\Examples\RealWorld\Invoice;
use PhpstanContracts\Examples\RealWorld\Nullables;
use PhpstanContracts\Examples\RealWorld\OrderWorkflow;
use PhpstanContracts\Examples\RealWorld\Pagination;
use PhpstanContracts\Examples\RealWorld\RateLimit;
use PhpstanContracts\Examples\RealWorld\Shipping;
use PhpstanContracts\Examples\RealWorld\Uploads;
use PhpstanContracts\Examples\Rejected;
use PhpstanContracts\Tests\Support\RuleFixtureTestCase;

/**
 * The examples are what the documentation shows, so what the prover says about
 * each of them is part of the contract of this package. A `*Broken`, `*Naive` or
 * `*Unbounded` method is expected to be refuted, every other one to be proven.
 *
 * `SolverLimits` is covered by its own test, with a short timeout.
 */
final class ExamplesTest extends RuleFixtureTestCase
{
    public function testWhatTheProverSaysAboutTheExamples(): void
    {
        $this->assertOutcomes([
            Pricing::class => [
                'stayTotal' => [],
                'loyaltyBonus' => [],
                'totalWithTier' => ['contracts.ensuresViolated'],
                'totalWithTierCapped' => [],
            ],
            Calendar::class => [
                'isLeapYear' => [],
                'isLeapYearBroken' => ['contracts.ensuresViolated'],
                'daysInMonth' => [],
            ],
            CancellationPolicy::class => [
                'refundCents' => [],
                'refundCentsBroken' => ['contracts.ensuresViolated'],
            ],
            Checkout::class => [
                'receipt' => [],
                'receiptBroken' => ['contracts.ensuresViolated', 'contracts.returnType'],
                'minimumShortfall' => [],
                'alwaysApplicable' => [],
            ],
            CurrencyParser::class => [
                'fromQuery' => [],
                'fromQueryBroken' => ['contracts.invalidEnumValue'],
                'roundTrip' => [],
                'symbol' => [],
                'symbolBroken' => ['contracts.ensuresViolated'],
            ],
            Floats::class => [
                'midpointBroken' => ['contracts.ensuresViolated'],
                'midpointSubtractBroken' => ['contracts.ensuresViolated'],
                'midpoint' => [],
                'listPriceCents' => ['contracts.ensuresViolated'],
                'withVat' => [],
                'sumRegrouped' => ['contracts.ensuresViolated'],
                'magnitude' => ['contracts.ensuresViolated'],
                'magnitudeOfNumber' => [],
            ],
            Invoice::class => [
                'vatCents' => [],
                'vatCentsNaive' => ['contracts.ensuresViolated'],
                'vatCentsSigned' => [],
                'installmentCents' => [],
                'sumOfInstallments' => [],
                'sumOfInstallmentsNaive' => ['contracts.ensuresViolated'],
            ],
            Nullables::class => [
                'daysLeft' => [],
                'bestDiscount' => [],
                'pageSize' => [],
                'pageSizeBroken' => ['contracts.ensuresViolated'],
                'unchecked' => ['contracts.unsupported'],
            ],
            OrderWorkflow::class => [
                'canTransition' => [],
                'canTransitionBroken' => ['contracts.ensuresViolated'],
                'isFinal' => [],
            ],
            Pagination::class => [
                'pageCount' => [],
                'pageCountBroken' => ['contracts.ensuresViolated'],
                'clampPage' => [],
                'offset' => [],
            ],
            RateLimit::class => [
                'refilledTokens' => [],
                'refilledTokensUnbounded' => ['contracts.ensuresViolated', 'contracts.overflow'],
                'secondsUntilAvailable' => [],
            ],
            Shipping::class => [
                'shippingCents' => [],
                'shippingCentsBroken' => ['contracts.ensuresViolated'],
            ],
            Uploads::class => [
                'isAllowedImage' => [],
                'isAllowedImageNaive' => ['contracts.ensuresViolated'],
                'isAllowedImageBroken' => ['contracts.ensuresViolated'],
                'isStaffCode' => [],
                'isValidUsername' => [],
                'storageKey' => [],
                'storageKeyBroken' => ['contracts.ensuresViolated'],
            ],
            Rejected::class => [
                'unboundedProduct' => ['contracts.overflow'],
                'unguardedDivision' => ['contracts.divisionByZero', 'contracts.ensuresViolated', 'contracts.overflow'],
                'guardedDivision' => [],
                'contradictory' => ['contracts.contradictoryRequires'],
                'missingReturn' => ['contracts.missingReturn'],
                'partialMatch' => ['contracts.unhandledMatch'],
                'loop' => ['contracts.unsupported: Loops are not supported'],
                'rounding' => ['contracts.unsupported: Call to round()'],
                'array' => ['contracts.unsupported: Type list<int> is not supported'],
                'effect' => ['contracts.unsupported: Side effects'],
                'validatedConstructor' => ['contracts.unsupported: its constructor contains logic'],
                'mutableObject' => ['contracts.unsupported: must be public and readonly'],
                'dynamic' => ['contracts.unsupported: Type object is not supported'],
                'brokenContract' => ['contracts.invalidContract: is not valid PHP'],
            ],
        ]);
    }
}
