<?php

namespace App\Contract\Notification;

use App\Models\Order;
use App\Models\Product;

/**
 * Tells the shop's own staff about order events they have to act on.
 *
 * Same delivery rule as OrderNotifierContract: every method swallows and
 * logs its own failures, because it runs inside payment and courier
 * callbacks that must never fail on account of mail.
 */
interface StaffOrderNotifierContract
{
    /**
     * Payment arrived from the gateway; the order is ready to pack.
     */
    public function orderPaid(Order $order): void;

    /**
     * A paid order was cancelled and the customer was promised a refund.
     */
    public function refundNeeded(Order $order): void;

    /**
     * The courier reported a status that needs a person to look at it.
     */
    public function shipmentProblem(Order $order, string $courierStatus): void;

    /**
     * A product's stock just fell to or below the low-stock threshold.
     */
    public function lowStock(Product $product, int $threshold): void;
}
