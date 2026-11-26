<?php

declare(strict_types=1);

namespace PhpstanContracts\Tests\Examples;

use PhpstanContracts\Examples\CartLimits;
use PhpstanContracts\Examples\Pricing;
use PhpstanContracts\Examples\RealWorld\Calendar;
use PhpstanContracts\Examples\RealWorld\CancellationPolicy;
use PhpstanContracts\Examples\RealWorld\Checkout;
use PhpstanContracts\Examples\RealWorld\CurrencyParser;
use PhpstanContracts\Examples\RealWorld\CustomerCode;
use PhpstanContracts\Examples\RealWorld\DeliveryLabel;
use PhpstanContracts\Examples\RealWorld\DiscountSplit;
use PhpstanContracts\Examples\RealWorld\ErpCode;
use PhpstanContracts\Examples\RealWorld\ErpExport;
use PhpstanContracts\Examples\RealWorld\Floats;
use PhpstanContracts\Examples\RealWorld\Invoice;
use PhpstanContracts\Examples\RealWorld\LoyaltyPricing;
use PhpstanContracts\Examples\RealWorld\LoyaltyTier;
use PhpstanContracts\Examples\RealWorld\Nullables;
use PhpstanContracts\Examples\RealWorld\OrderLines;
use PhpstanContracts\Examples\RealWorld\OrderTotals;
use PhpstanContracts\Examples\RealWorld\OrderWorkflow;
use PhpstanContracts\Examples\RealWorld\Pagination;
use PhpstanContracts\Examples\RealWorld\PriceContext;
use PhpstanContracts\Examples\RealWorld\ProportionalSplit;
use PhpstanContracts\Examples\RealWorld\RateLimit;
use PhpstanContracts\Examples\RealWorld\ReconciliationDeltaGuard;
use PhpstanContracts\Examples\RealWorld\RefundPolicy;
use PhpstanContracts\Examples\RealWorld\Shipping;
use PhpstanContracts\Examples\RealWorld\StockAvailability;
use PhpstanContracts\Examples\RealWorld\StockLine;
use PhpstanContracts\Examples\RealWorld\StockStatus;
use PhpstanContracts\Examples\RealWorld\Uploads;
use PhpstanContracts\Examples\RealWorld\UserRole;
use PhpstanContracts\Examples\RealWorld\VatSplit;
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
            OrderLines::class => [
                'lineAmount' => [],
                'lineAmountBroken' => ['contracts.undefinedKey: Counterexample: $amounts = '],
                'firstAmount' => [],
                'withLine' => [],
                'withLineBroken' => ['contracts.returnType: The returned value can violate the declared return type `list<int<0, 10000000>>`. Counterexample: $amounts = '],
                'isBlocked' => [],
            ],
            DiscountSplit::class => [
                'evenly' => [],
                'evenlyBroken' => ['contracts.ensuresViolated: Counterexample: $discountCents = '],
                'cappedByLine' => [],
                'cappedByLineBroken' => ['contracts.ensuresViolated: Counterexample: $discountCents = '],
            ],
            ProportionalSplit::class => [
                'proportional' => [],
                'proportionalBroken' => ['contracts.ensuresViolated: Counterexample: $totalDiscount = '],
            ],
            OrderTotals::class => [
                'total' => [],
                'totalBroken' => ['contracts.loopBound: The proof unrolls this foreach up to 10 iterations, and $lines can have more. This is a limit of the proof, not a bug in the code.'],
                'firstLineAbove' => [],
                'firstLineAboveBroken' => ['contracts.ensuresViolated: Counterexample: $lines = '],
                'withoutEmptyLines' => [],
                'withoutEmptyLinesBroken' => ['contracts.ensuresViolated: Counterexample: $lines = '],
            ],
            VatSplit::class => [
                'split' => [],
                'splitBroken' => ['contracts.ensuresViolated: Counterexample: $grossCents = '],
                'vatShare' => [],
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
            ReconciliationDeltaGuard::class => [
                'isMatched' => [],
                'isMatchedBroken' => ['contracts.ensuresViolated'],
                'deltaCents' => [],
                'withinTolerance' => [],
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
                'tryFromBroken' => ['contracts.ensuresViolated: Counterexample: $webId = '],
                'from' => [],
                'shard' => [],
                'shardBroken' => ['contracts.unexpectedThrow: `throw new \\LogicException(\'Unknown shard.\')` can be reached where no #[Throws] allows it. Counterexample: $webId = '],
                'fromBroken' => ['contracts.throwsViolated: the method can return although the condition holds. Counterexample: $webId = '],
            ],
            CustomerCode::class => [
                'from' => [],
                'fromBroken' => ['contracts.throwsViolated: the method can return although the condition holds. Counterexample: $webId = '],
            ],
            LoyaltyTier::class => [
                'discountBasisPoints' => [],
                'discountBasisPointsBroken' => ['contracts.ensuresViolated: Counterexample: $this = LoyaltyTier::Gold => $result = 10500'],
            ],
            LoyaltyPricing::class => [
                'priceAfterLoyalty' => [],
                'priceAfterLoyaltyBroken' => ['contracts.ensuresViolated: Counterexample: $cents = 20, $tier = LoyaltyTier::Gold => $result = -1'],
            ],
            RefundPolicy::class => [
                'refund' => [],
                'refundBroken' => ['contracts.ensuresViolated: Counterexample: $paidCents = 10, $daysBeforeStay = 7 => $result = Refund(paidCents: 10, refundedCents: 11)'],
            ],
            ErpExport::class => [
                'exportLine' => [],
                'exportLineBroken' => ['contracts.unexpectedThrow: `throw new \\InvalidArgumentException(\'The web id is outside the range reserved for the web shop.\')` can be reached where the #[Throws] declarations do not allow it'],
            ],
            UserRole::class => [
                'hierarchyLevel' => [],
                'canManage' => [],
                'canManageBroken' => ['contracts.ensuresViolated: Counterexample: $this = UserRole::'],
                'canValidate' => [],
                'canPrescribeBroken' => ['contracts.ensuresViolated: Counterexample: $this = UserRole::'],
            ],
            DeliveryLabel::class => [
                'inStockDelay' => [],
                'inStockDelayBroken' => ['contracts.ensuresViolated: Counterexample: $days = 1 => $result = \'Ships in 1 days\''],
                'orderReference' => [],
                'plural' => [],
                'shortDelay' => [],
                'shortDelayBroken' => ['contracts.requiresViolated: The call to PhpstanContracts\Examples\RealWorld\DeliveryLabel::plural() can violate its precondition #[Requires(\'$days >= 2\')]'],
            ],
            PriceContext::class => [
                'discountPercent' => [],
                'discountPercentBroken' => ['contracts.ensuresViolated: Counterexample: $this = PriceContext(quantity: 10'],
                'hasNoExtraStateBroken' => ['contracts.unsupported: Property PhpstanContracts\Examples\RealWorld\PriceContext::$state must be public and readonly'],
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
                'isImageExtension' => [],
                'isImageExtensionBroken' => ['contracts.ensuresViolated: Counterexample: $extension = \'jpeg\''],
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
                'loop' => ['contracts.unsupported: Only foreach over a list is supported: a while, do or for loop needs a termination argument'],
                'rounding' => ['contracts.unsupported: Call to round()'],
                'array' => ['contracts.unsupported: Type array<string, int> is not supported in verified methods'],
                'looseArrayEquality' => ['contracts.unsupported: Arrays compared with == are compared loosely'],
                'effect' => ['contracts.unsupported: Side effects'],
                'validatedConstructor' => ['contracts.unsupported: its constructor contains logic'],
                'mutableObject' => ['contracts.unsupported: must be public and readonly'],
                'dynamic' => ['contracts.unsupported: Type object is not supported'],
                'looseInArray' => ['contracts.unsupported: in_array() without strict: true compares with =='],
                'undeclaredThrow' => ['contracts.unexpectedThrow: `throw new \\InvalidArgumentException(\'Negative.\')` can be reached where no #[Throws] allows it. Counterexample: $x = '],
                'brokenContract' => ['contracts.invalidContract: is not valid PHP'],
                'builtInEnumMethod' => ['contracts.unsupported: ShippingZone::cases() is built into PHP, so there is no body to translate'],
            ],
            CartLimits::class => [
                'itemsLateBound' => ['contracts.unsupported: static::MAX_ITEMS is late bound'],
                'items' => [],
                'quantity' => [],
            ],
        ]);
    }
}
