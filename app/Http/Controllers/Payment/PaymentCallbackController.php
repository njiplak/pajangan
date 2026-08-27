<?php

namespace App\Http\Controllers\Payment;

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
    public function __construct(private readonly PaymentGatewayManager $gateways) {}

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

        DB::transaction(function () use ($gateway, $notification) {
            $order = Order::query()
                ->where('payment_gateway', $gateway)
                ->where('payment_reference', $notification['reference'])
                ->lockForUpdate()
                ->first();

            if (! $order) {
                Log::warning("Payment callback for unknown reference [{$notification['reference']}] on gateway [{$gateway}].");

                return;
            }

            if ($order->payment_status === PaymentStatus::PAID) {
                // Terminal state: never let a stale/out-of-order webhook downgrade a paid order.
                return;
            }

            if ($order->payment_status === $notification['status']) {
                // Duplicate delivery.
                return;
            }

            $order->payment_status = $notification['status'];
            $order->payment_payload = $notification['raw'];

            if ($notification['status'] === PaymentStatus::PAID) {
                $order->paid_at = $notification['paid_at'] ?? now();
                $order->status = Order::STATUS_PAID;
            }

            $order->save();
        });

        return response()->json(['message' => 'ok']);
    }
}
