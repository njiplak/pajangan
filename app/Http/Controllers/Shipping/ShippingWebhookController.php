<?php

namespace App\Http\Controllers\Shipping;

use App\Contract\Notification\OrderNotifierContract;
use App\Contract\Notification\StaffOrderNotifierContract;
use App\Http\Controllers\Controller;
use App\Models\Order;
use App\Service\Order\OrderActivityLog;
use App\Service\Shipping\ShippingProviderManager;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use RuntimeException;

class ShippingWebhookController extends Controller
{
    /**
     * Fulfillment progression only — pending/paid/processing/shipped/
     * completed, in that order. Biteship's "problem" statuses (cancelled, rejected,
     * courier_not_found, returned, return_in_transit, on_hold, disposed)
     * are deliberately NOT mapped here: guessing them onto our status
     * enum risks mislabeling a real problem as a clean state, so those
     * are left for staff to notice and handle manually instead.
     *
     * Biteship's docs show these status strings inconsistently cased
     * (snake_case in some places, camelCase in others) — matching is
     * case/separator-insensitive so either form works.
     */
    private const STATUS_PROGRESSION = ['pending', 'paid', 'processing', 'shipped', 'completed'];

    /**
     * The unmapped statuses above, normalized. Each one alerts staff the
     * first time the courier reports it.
     */
    private const PROBLEM_STATUSES = [
        'cancelled',
        'rejected',
        'couriernotfound',
        'returned',
        'returnintransit',
        'onhold',
        'disposed',
    ];

    private const STATUS_MAP = [
        'confirmed' => Order::STATUS_PROCESSING,
        'allocated' => Order::STATUS_PROCESSING,
        'pickingup' => Order::STATUS_PROCESSING,
        'picked' => Order::STATUS_SHIPPED,
        'intransit' => Order::STATUS_SHIPPED,
        'droppingoff' => Order::STATUS_SHIPPED,
        'delivered' => Order::STATUS_COMPLETED,
    ];

    public function __construct(
        private readonly ShippingProviderManager $shipping,
        private readonly OrderNotifierContract $notifier,
        private readonly StaffOrderNotifierContract $staffNotifier,
        private readonly OrderActivityLog $activity,
    ) {}

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

        $outcome = DB::transaction(function () use ($order, $shipment) {
            $locked = Order::query()->whereKey($order->id)->lockForUpdate()->first();

            $previousTracking = $locked->tracking_number;
            $previousCourierStatus = $this->normalize($locked->shipment_status ?? '');
            $courierStatus = $this->normalize($shipment['status'] ?? '');

            $update = [
                'tracking_number' => $shipment['tracking_number'],
                'courier_code' => $shipment['courier_code'] ?: $locked->courier_code,
                'courier_service' => $shipment['courier_service'] ?: $locked->courier_service,
                'shipping_cost' => $shipment['price'] ?? $locked->shipping_cost,
                'shipment_status' => $shipment['status'] ?? $locked->shipment_status,
            ];

            $mappedStatus = self::STATUS_MAP[$courierStatus] ?? null;

            if ($mappedStatus && $this->isForwardProgress($locked->status, $mappedStatus)) {
                $update['status'] = $mappedStatus;
            }

            $locked->update($update);

            if ($courierStatus !== $previousCourierStatus && $courierStatus !== '') {
                $this->activity->record(
                    $locked,
                    'courier',
                    "Kurir: {$shipment['status']}"
                        .(isset($update['status']) ? ' → status '.Order::statusLabel($update['status']) : '').'.',
                );
            }

            // Only the delivery that actually moves the order to completed
            // mails the customer; later repeats of the same status are a
            // no-op above.
            $delivered = ($update['status'] ?? null) === Order::STATUS_COMPLETED;

            return [
                'order' => $locked,
                'delivered' => $delivered,
                // Couriers often assign the waybill after the shipment was
                // created, so the customer's first shipped email had none.
                'newTracking' => ! $delivered
                    && $locked->isInFulfillment()
                    && filled($locked->tracking_number)
                    && $locked->tracking_number !== $previousTracking,
                'problem' => in_array($courierStatus, self::PROBLEM_STATUSES, true)
                    && $courierStatus !== $previousCourierStatus,
            ];
        });

        if ($outcome['delivered']) {
            $this->notifier->orderDelivered($outcome['order']);
        }

        if ($outcome['newTracking']) {
            $this->notifier->orderShipped($outcome['order']);
        }

        if ($outcome['problem']) {
            $this->staffNotifier->shipmentProblem($outcome['order'], (string) $shipment['status']);
        }

        return response()->json(['message' => 'ok']);
    }

    private function normalize(string $biteshipStatus): string
    {
        return str_replace('_', '', mb_strtolower($biteshipStatus));
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
