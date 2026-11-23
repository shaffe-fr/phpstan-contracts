<?php

declare(strict_types=1);

namespace PhpstanContracts\Examples\RealWorld;

use PhpstanContracts\Attribute\Ensures;

final class Checkout
{
    /**
     * Applies an optional coupon to a cart. A coupon with a minimum spend only applies above it.
     *
     * PHPStan cannot see that the total stays non-negative, so it asks about the Receipt
     * constructor argument; the first postcondition below is the proof that answers it.
     */
    #[Ensures('$result->totalCents >= 0')]
    #[Ensures('$result->discountCents + $result->totalCents === $cart->subtotalCents')]
    #[Ensures('implies($cart->coupon === null, !$result->couponApplied && $result->discountCents === 0)')]
    #[Ensures('implies($result->couponApplied, $cart->coupon !== null)')]
    #[Ensures('implies($result->couponApplied, $result->discountCents <= $cart->subtotalCents)')]
    public static function receipt(Cart $cart): Receipt
    {
        $coupon = $cart->coupon;
        if ($coupon === null) {
            return new Receipt(0, $cart->subtotalCents, false);
        }

        if ($coupon->minimumCents !== null && $cart->subtotalCents < $coupon->minimumCents) {
            return new Receipt(0, $cart->subtotalCents, false);
        }

        $discount = intdiv($cart->subtotalCents * $coupon->percent, 100);

        return new Receipt($discount, $cart->subtotalCents - $discount, true); // @phpstan-ignore argument.type
    }

    /**
     * A flat 5.00 welcome bonus was added on top of the percentage.
     */
    #[Ensures('$result->totalCents >= 0')]
    public static function receiptBroken(Cart $cart): Receipt
    {
        $coupon = $cart->coupon;
        if ($coupon === null) {
            return new Receipt(0, $cart->subtotalCents, false);
        }

        $discount = intdiv($cart->subtotalCents * $coupon->percent, 100) + 500;

        return new Receipt($discount, $cart->subtotalCents - $discount, true);
    }

    /**
     * How much more the customer must spend for the coupon to apply.
     */
    #[Ensures('$result >= 0')]
    #[Ensures('implies($cart->coupon?->minimumCents === null, $result === 0)')]
    public static function minimumShortfall(Cart $cart): int
    {
        $minimum = $cart->coupon?->minimumCents;

        return $minimum === null ? 0 : max(0, $minimum - $cart->subtotalCents);
    }

    /**
     * The same rule written with a nested null check instead of a local variable.
     */
    #[Ensures('$result === ($cart->coupon !== null && $cart->coupon->minimumCents === null)')]
    public static function alwaysApplicable(Cart $cart): bool
    {
        return $cart->coupon !== null && $cart->coupon->minimumCents === null;
    }
}

/**
 * Declared in this file because Checkout builds it: a verified method can only build an
 * object whose constructor is in the same file.
 */
final readonly class Receipt
{
    public function __construct(
        /** @var int<0, 100000000> */
        public int $discountCents,
        /** @var int<0, 100000000> */
        public int $totalCents,
        public bool $couponApplied,
    ) {
    }
}
