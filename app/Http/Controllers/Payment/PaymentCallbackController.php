<?php

namespace App\Http\Controllers\Payment;

use App\Http\Controllers\Controller;
use App\Models\Order;
use App\Service\Payment\OrderPaymentRecorder;
use App\Service\Payment\PaymentGatewayManager;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use InvalidArgumentException;

class PaymentCallbackController extends Controller
{
    public function __construct(
        private readonly PaymentGatewayManager $gateways,
        private readonly OrderPaymentRecorder $recorder,
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

        $order = Order::query()
            ->where('payment_gateway', $gateway)
            ->where('payment_reference', $notification['reference'])
            ->first();

        if (! $order) {
            Log::warning("Payment callback for unknown reference [{$notification['reference']}] on gateway [{$gateway}].");

            return response()->json(['message' => 'ok']);
        }

        $this->recorder->record($order, $notification);

        return response()->json(['message' => 'ok']);
    }
}
