<?php

namespace App\Contract\Notification;

use App\Models\Order;

/**
 * Tells a customer what just happened to their order.
 *
 * Kept as a contract because the channel is a business decision, not a
 * property of the events: email ships today, and WhatsApp — the channel
 * this market actually reads — can be added as a second implementation
 * without touching any of the call sites below.
 *
 * Every method must swallow and log its own delivery failures. These are
 * called from the middle of checkout and payment handling, where a dead
 * mail server must never cost a customer their order.
 */
interface OrderNotifierContract
{
    /**
     * The order exists and is awaiting payment. Carries the signed link
     * the customer needs to find it again — without this message that
     * link is shown once and then lost.
     */
    public function orderPlaced(Order $order): void;

    /**
     * Payment has been confirmed by the gateway.
     */
    public function paymentReceived(Order $order): void;

    /**
     * A shipment exists at the courier and, usually, has a tracking number.
     */
    public function orderShipped(Order $order): void;

    /**
     * The shipment the customer was told about was withdrawn, so the
     * tracking number they hold no longer leads anywhere.
     */
    public function orderShipmentCancelled(Order $order, ?string $voidTrackingNumber): void;

    /**
     * The courier reports the parcel as delivered.
     */
    public function orderDelivered(Order $order): void;

    /**
     * The order will not go ahead — typically because payment never
     * arrived within the hold window and the stock has gone back.
     */
    public function orderCancelled(Order $order): void;

    /**
     * Staff have sent back the money for a cancelled order.
     */
    public function orderRefunded(Order $order): void;
}
