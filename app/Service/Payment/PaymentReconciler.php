<?php

namespace App\Service\Payment;

use App\Models\Order;
use InvalidArgumentException;
use RuntimeException;

/**
 * Asks the gateway directly what happened to an order's payment, for when
 * its webhook never arrived.
 */
class PaymentReconciler
{
    public function __construct(
        private readonly PaymentGatewayManager $gateways,
        private readonly OrderPaymentRecorder $recorder,
    ) {}

    public function canCheck(Order $order): bool
    {
        return filled($order->payment_gateway) && filled($order->payment_reference);
    }

    /**
     * @return string one of the OrderPaymentRecorder outcomes
     *
     * @throws RuntimeException when the order has no transaction or the gateway can't be asked
     */
    public function check(Order $order): string
    {
        if (! $this->canCheck($order)) {
            throw new RuntimeException("Pesanan {$order->order_number} tidak punya transaksi di payment gateway.");
        }

        try {
            $gateway = $this->gateways->resolve($order->payment_gateway);
        } catch (InvalidArgumentException) {
            throw new RuntimeException("Payment gateway [{$order->payment_gateway}] untuk pesanan {$order->order_number} tidak terpasang.");
        }

        $report = $gateway->getStatus($order->payment_reference);

        return $this->recorder->record($order, $report);
    }
}
