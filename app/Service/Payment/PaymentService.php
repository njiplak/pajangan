<?php

namespace App\Service\Payment;

use App\Contract\Payment\PaymentStatus;
use App\Contract\Setting\SettingContract;
use App\Models\Order;
use RuntimeException;

/**
 * Orchestrates starting a payment for an order: resolves the active
 * gateway, applies the admin-fee policy, calls the gateway, and
 * persists the result onto the order. Kept separate from the
 * gateway contract itself so fee policy stays a store-level business
 * rule rather than something each gateway implementation has to know.
 */
class PaymentService
{
    public function __construct(
        private readonly PaymentGatewayManager $gateways,
        private readonly SettingContract $settings,
    ) {}

    /**
     * The gateway key the store is currently configured to use, per the
     * `payment_active_gateway` Setting. Falls back to the first
     * registered-and-configured gateway if unset.
     */
    public function activeGatewayKey(): ?string
    {
        $configured = $this->gateways->configured();
        $active = $this->settings->allAsKeyValue()['payment_active_gateway'] ?? null;

        if ($active && in_array($active, $configured, true)) {
            return $active;
        }

        return $configured[0] ?? null;
    }

    /**
     * Start a payment transaction for the given order.
     *
     * @param  array  $options  passed through to the gateway (e.g. options[method] for the channel)
     * @return array{redirect_url: ?string, token: ?string, raw: array}
     */
    public function initiate(Order $order, string $gatewayKey, array $options = []): array
    {
        $gateway = $this->gateways->resolve($gatewayKey);

        if (! $gateway->isConfigured()) {
            throw new RuntimeException("Payment gateway [{$gatewayKey}] is not configured.");
        }

        $settings = $this->settings->allAsKeyValue();

        // Gateways that hand off to their own hosted payment page
        // (Midtrans Snap, Xendit Invoice, DOKU Checkout) let the customer
        // pick a channel there and don't need this. Gateways that create
        // a fixed-channel transaction up front (Tripay, Duitku) do, and
        // this app has no channel-picker UI, so fall back to one
        // store-wide default channel rather than hard-failing checkout.
        if (empty($options['method']) && ! empty($settings['payment_default_channel'])) {
            $options['method'] = $settings['payment_default_channel'];
        }

        [$fee, $borneBy] = $this->resolveAdminFee($settings, $gatewayKey, $options['method'] ?? null, $order->subtotal);
        $amount = $order->subtotal + (int) ($order->shipping_cost ?? 0) + $fee;

        $result = $gateway->createTransaction($order, $amount, $options);

        $order->update([
            'payment_gateway' => $gatewayKey,
            'payment_channel' => $options['method'] ?? null,
            'payment_reference' => $result['reference'],
            'payment_status' => PaymentStatus::PENDING,
            'admin_fee' => $fee,
            'fee_borne_by' => $borneBy,
            'total' => $amount,
            'payment_payload' => $result['raw'],
        ]);

        return $result;
    }

    /**
     * @return array{0: int, 1: string} [fee amount in the order's currency unit, who bears it]
     *
     * Looks up a fee configured specifically for this (gateway, channel)
     * pair first — set via the admin's per-channel fee schedule, usually
     * pre-filled from the gateway's own real channel fee data — and
     * falls back to the store-wide flat fee when no channel-specific
     * entry exists (always the case for hosted-page gateways, since we
     * never know the channel before the customer picks one there).
     */
    private function resolveAdminFee(array $settings, string $gatewayKey, ?string $channel, int $subtotal): array
    {
        $borneBy = $settings['payment_admin_fee_borne_by'] ?? 'merchant';

        if ($borneBy !== 'customer') {
            return [0, $borneBy];
        }

        $channelFees = json_decode($settings['payment_channel_fees'] ?? '{}', true) ?: [];
        $entry = $channel ? ($channelFees[$gatewayKey][$channel] ?? null) : null;

        if ($entry) {
            $flat = (int) ($entry['flat'] ?? 0);
            $percent = (float) ($entry['percent'] ?? 0);

            return [$flat + (int) round($subtotal * $percent / 100), $borneBy];
        }

        return [(int) ($settings['payment_admin_fee_flat'] ?? 0), $borneBy];
    }
}
