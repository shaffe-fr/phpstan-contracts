<?php

declare(strict_types=1);

namespace PhpstanContracts\Examples\RealWorld;

use PhpstanContracts\Attribute\Ensures;

final class OrderWorkflow
{
    /**
     * The allowed status transitions of an order.
     */
    #[Ensures('implies($from === OrderStatus::Cancelled || $from === OrderStatus::Refunded, !$result)')]
    #[Ensures('implies($to === OrderStatus::Pending, !$result)')]
    #[Ensures('implies($from === $to, !$result)')]
    #[Ensures('implies($from === OrderStatus::Shipped && $result, $to === OrderStatus::Delivered)')]
    public static function canTransition(OrderStatus $from, OrderStatus $to): bool
    {
        return match ($from) {
            OrderStatus::Pending => $to === OrderStatus::Paid || $to === OrderStatus::Cancelled,
            OrderStatus::Paid => $to === OrderStatus::Shipped
                || $to === OrderStatus::Cancelled
                || $to === OrderStatus::Refunded,
            OrderStatus::Shipped => $to === OrderStatus::Delivered,
            OrderStatus::Delivered => $to === OrderStatus::Refunded,
            OrderStatus::Cancelled, OrderStatus::Refunded => false,
        };
    }

    /**
     * A "small" change that lets customers cancel an order the carrier already holds.
     */
    #[Ensures('implies($from === OrderStatus::Shipped && $result, $to === OrderStatus::Delivered)')]
    public static function canTransitionBroken(OrderStatus $from, OrderStatus $to): bool
    {
        return match ($from) {
            OrderStatus::Pending => $to === OrderStatus::Paid || $to === OrderStatus::Cancelled,
            OrderStatus::Paid => $to === OrderStatus::Shipped
                || $to === OrderStatus::Cancelled
                || $to === OrderStatus::Refunded,
            OrderStatus::Shipped => $to === OrderStatus::Delivered || $to === OrderStatus::Cancelled,
            OrderStatus::Delivered => $to === OrderStatus::Refunded,
            OrderStatus::Cancelled, OrderStatus::Refunded => false,
        };
    }

    /**
     * Statuses from which nothing can happen any more.
     */
    #[Ensures('$result === ($status === OrderStatus::Cancelled || $status === OrderStatus::Refunded)')]
    public static function isFinal(OrderStatus $status): bool
    {
        return match ($status) {
            OrderStatus::Cancelled, OrderStatus::Refunded => true,
            OrderStatus::Pending, OrderStatus::Paid, OrderStatus::Shipped, OrderStatus::Delivered => false,
        };
    }
}
