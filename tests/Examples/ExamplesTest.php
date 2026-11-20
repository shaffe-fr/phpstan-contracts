<?php

declare(strict_types=1);

namespace PhpstanContracts\Tests\Examples;

use PhpstanContracts\Examples\CartLimits;
use PhpstanContracts\Examples\Pricing;
use PhpstanContracts\Examples\RealWorld\Calendar;
use PhpstanContracts\Examples\RealWorld\CancellationPolicy;
use PhpstanContracts\Examples\RealWorld\Checkout;
use PhpstanContracts\Examples\RealWorld\CurrencyParser;
use PhpstanContracts\Examples\RealWorld\DeliveryLabel;
use PhpstanContracts\Examples\RealWorld\ErpCode;
use PhpstanContracts\Examples\RealWorld\Floats;
use PhpstanContracts\Examples\RealWorld\Invoice;
use PhpstanContracts\Examples\RealWorld\Nullables;
use PhpstanContracts\Examples\RealWorld\OrderWorkflow;
use PhpstanContracts\Examples\RealWorld\Pagination;
use PhpstanContracts\Examples\RealWorld\PriceContext;
use PhpstanContracts\Examples\RealWorld\RateLimit;
use PhpstanContracts\Examples\RealWorld\Shipping;
use PhpstanContracts\Examples\RealWorld\StockAvailability;
use PhpstanContracts\Examples\RealWorld\StockLine;
use PhpstanContracts\Examples\RealWorld\StockStatus;
use PhpstanContracts\Examples\RealWorld\Uploads;
use PhpstanContracts\Examples\RealWorld\UserRole;
use PhpstanContracts\Examples\Rejected;
use PhpstanContracts\Tests\Support\RuleFixtureTestCase;

/**
 * The examples are what the documentation shows, so what the prover says about
 * each of them is part of the contract of this package. A `*Broken`, `*Naive` or
 * `*Unbounded` method is expected to be refuted, every other one to be proven,
 * except in `Rejected` and `CartLimits`, which show what is rejected.
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
            StockAvailability::class => [
                'resolve' => [],
                'resolveBroken' => ['contracts.ensuresViolated: Counterexample: $stockQuantity = 0 => $result = StockAvailability::Immediate'],
            ],
            ErpCode::class => [
                'tryFrom' => [],
                'tryFromBroken' => ['contracts.ensuresViolated: Counterexample: $webId = 1000000 => $result = 99000000'],
                'from' => [],
                'fromBroken' => ['contracts.throwsViolated: the method can return although the condition holds. Counterexample: $webId = 1000000 => $result = 99000000'],
            ],
            UserRole::class => [
                'hierarchyLevel' => [],
                'canManage' => [],
                'canManageBroken' => ['contracts.ensuresViolated: Counterexample: $this = UserRole::'],
                'canValidate' => [],
                'canPrescribeBroken' => ['contracts.ensuresViolated: Counterexample: $this = UserRole::Validator => $result = false'],
            ],
            DeliveryLabel::class => [
                'inStockDelay' => [],
                'inStockDelayBroken' => ['contracts.ensuresViolated: Counterexample: $days = 1 => $result = \'Ships in 1 days\''],
                'orderReference' => [],
            ],
            PriceContext::class => [
                'discountPercent' => [],
                'discountPercentBroken' => ['contracts.ensuresViolated: Counterexample: $this = PriceContext(quantity: 10'],
            ],
            StockStatus::class => [
                'isPurchasable' => [],
                'isPurchasableBroken' => ['contracts.ensuresViolated: Counterexample: $this = StockStatus::TemporarilyUnavailable => $result = true'],
                'erpCode' => [],
            ],
            StockLine::class => [
                'available' => [],
                'availableBroken' => ['contracts.ensuresViolated: Counterexample: $this = StockLine('],
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
                'frameworkState' => ['contracts.unsupported: Property PhpstanContracts\Examples\RealWorld\PriceContext::$additional must be public and readonly'],
                'callToAnotherFile' => ['contracts.unsupported: PhpstanContracts\Examples\RealWorld\PriceContext::discountPercent() is declared in another file.'],
                'dynamic' => ['contracts.unsupported: Type object is not supported'],
                'brokenContract' => ['contracts.invalidContract: is not valid PHP'],
            ],
            CartLimits::class => [
                'itemsLateBound' => ['contracts.unsupported: static::MAX_ITEMS is late bound'],
                'items' => [],
                'quantity' => [],
            ],
        ]);
    }
}
