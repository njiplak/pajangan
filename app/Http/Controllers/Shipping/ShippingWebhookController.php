<?php

namespace App\Http\Controllers\Shipping;

use App\Http\Controllers\Controller;
use App\Models\Order;
use App\Service\Shipping\ShippingProviderManager;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use RuntimeException;

class ShippingWebhookController extends Controller
{
    /**
     * Fulfillment progression only — pending/processing/shipped/completed,
     * in that order. Biteship's "problem" statuses (cancelled, rejected,
     * courier_not_found, returned, return_in_transit, on_hold, disposed)
     * are deliberately NOT mapped here: guessing them onto our status
     * enum risks mislabeling a real problem as a clean state, so those
     * are left for staff to notice and handle manually instead.
     *
     * Biteship's docs show these status strings inconsistently cased
     * (snake_case in some places, camelCase in others) — matching is
     * case/separator-insensitive so either form works.
     */
    private const STATUS_PROGRESSION = ['pending', 'processing', 'shipped', 'completed'];

    private const STATUS_MAP = [
        'confirmed' => Order::STATUS_PROCESSING,
        'allocated' => Order::STATUS_PROCESSING,
        'pickingup' => Order::STATUS_PROCESSING,
        'picked' => Order::STATUS_SHIPPED,
        'intransit' => Order::STATUS_SHIPPED,
        'droppingoff' => Order::STATUS_SHIPPED,
        'delivered' => Order::STATUS_COMPLETED,
    ];

    public function __construct(private readonly ShippingProviderManager $shipping) {}

    /**
     * Biteship does not sign its webhook payloads, so this endpoint trusts
     * nothing from the request body — the URL token narrows who can even
     * reach it, and the token in the payload is used only to know which
     * order to re-fetch from Biteship's own API. What gets written to the
     * order always comes from that authoritative re-fetch, never from the
     * inbound JSON.
     */
    public function handle(Request $request, string $token)
    {
        $expected = config('services.biteship.webhook_token');

        if (blank($expected) || ! hash_equals($expected, $token)) {
            Log::warning('Biteship webhook received with an invalid or unconfigured token.');

            return response()->json(['message' => 'ok']);
        }

        $providerOrderId = (string) $request->input('order_id', '');

        if ($providerOrderId === '') {
            return response()->json(['message' => 'ok']);
        }

        $order = Order::query()->where('biteship_order_id', $providerOrderId)->first();

        if (! $order) {
            Log::warning("Biteship webhook for unknown order [{$providerOrderId}].");

            return response()->json(['message' => 'ok']);
        }

        try {
            $shipment = $this->shipping->resolve('biteship')->getShipmentStatus($providerOrderId);
        } catch (RuntimeException $e) {
            Log::error("Biteship shipment status re-fetch failed for order [{$providerOrderId}]: {$e->getMessage()}");

            return response()->json(['message' => 'ok']);
        }

        DB::transaction(function () use ($order, $shipment) {
            $locked = Order::query()->whereKey($order->id)->lockForUpdate()->first();

            $update = [
                'tracking_number' => $shipment['tracking_number'],
                'courier_code' => $shipment['courier_code'] ?: $locked->courier_code,
                'courier_service' => $shipment['courier_service'] ?: $locked->courier_service,
                'shipping_cost' => $shipment['price'] ?? $locked->shipping_cost,
            ];

            $mappedStatus = $this->mapToFulfillmentStatus($shipment['status'] ?? '');

            if ($mappedStatus && $this->isForwardProgress($locked->status, $mappedStatus)) {
                $update['status'] = $mappedStatus;
            }

            $locked->update($update);
        });

        return response()->json(['message' => 'ok']);
    }

    private function mapToFulfillmentStatus(string $biteshipStatus): ?string
    {
        $normalized = str_replace('_', '', mb_strtolower($biteshipStatus));

        return self::STATUS_MAP[$normalized] ?? null;
    }

    private function isForwardProgress(string $currentStatus, string $newStatus): bool
    {
        $currentIndex = array_search($currentStatus, self::STATUS_PROGRESSION, true);
        $newIndex = array_search($newStatus, self::STATUS_PROGRESSION, true);

        if ($currentIndex === false || $newIndex === false) {
            // Current status is outside the linear progression (e.g.
            // already 'cancelled') — never move it via webhook.
            return false;
        }

        return $newIndex > $currentIndex;
    }
}
