<?php

namespace App\Http\Controllers\Payment;

use App\Contract\Notification\OrderNotifierContract;
use App\Contract\Payment\PaymentStatus;
use App\Http\Controllers\Controller;
use App\Models\Order;
use App\Service\Payment\PaymentGatewayManager;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use InvalidArgumentException;

class PaymentCallbackController extends Controller
{
    public function __construct(
        private readonly PaymentGatewayManager $gateways,
        private readonly OrderNotifierContract $notifier,
    ) {}

    public function handle(Request $request, string $gateway)
    {
        try {
            $service = $this->gateways->resolve($gateway);
        } catch (InvalidArgumentException) {
            abort(404);
        }

        $rawBody = $request->getContent();
        $headers = collect($request->headers->all())
            ->map(fn ($values) => $values[0] ?? null)
            ->all();

        if (! $service->verifyNotification($rawBody, $headers)) {
            Log::warning("Payment callback signature verification failed for gateway [{$gateway}].");

            return response()->json(['message' => 'invalid signature'], 403);
        }

        $notification = $service->parseNotification($request->all());

        // Returns the order only when this delivery is the one that moved
        // it to paid, so a duplicate or out-of-order webhook cannot mail
        // the customer a second receipt.
        $newlyPaid = DB::transaction(function () use ($gateway, $notification) {
            $order = Order::query()
                ->where('payment_gateway', $gateway)
                ->where('payment_reference', $notification['reference'])
                ->lockForUpdate()
                ->first();

            if (! $order) {
                Log::warning("Payment callback for unknown reference [{$notification['reference']}] on gateway [{$gateway}].");

                return null;
            }

            if ($order->payment_status === PaymentStatus::PAID) {
                // Terminal state: never let a stale/out-of-order webhook downgrade a paid order.
                return null;
            }

            if ($order->payment_status === $notification['status']) {
                // Duplicate delivery.
                return null;
            }

            $order->payment_status = $notification['status'];
            $order->payment_payload = $notification['raw'];

            if ($notification['status'] === PaymentStatus::PAID) {
                $order->paid_at = $notification['paid_at'] ?? now();
                $order->status = Order::STATUS_PAID;
            }

            $order->save();

            return $notification['status'] === PaymentStatus::PAID ? $order : null;
        });

        // Outside the transaction: a receipt must only go out for a write
        // that actually committed.
        if ($newlyPaid) {
            $this->notifier->paymentReceived($newlyPaid);
        }

        return response()->json(['message' => 'ok']);
    }
}
