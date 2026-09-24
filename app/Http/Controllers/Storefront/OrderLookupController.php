<?php

namespace App\Http\Controllers\Storefront;

use App\Contract\Payment\PaymentStatus;
use App\Http\Controllers\Controller;
use App\Models\Order;
use App\Service\Payment\PaymentService;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\URL;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Throwable;

class OrderLookupController extends Controller
{
    public function __construct(private readonly PaymentService $payment) {}

    public function show(Order $order)
    {
        $order->load('items');

        return Inertia::render('storefront/orders/show', [
            'order' => $order,
            // Drives the "Bayar Sekarang" button: without it a customer who
            // closed the gateway page has no route back to paying. The
            // action carries its own signature, since the page's signature
            // does not cover a different route.
            'payUrl' => $this->isPayable($order)
                ? URL::signedRoute('order.pay', ['order' => $order->order_number])
                : null,
        ]);
    }

    /**
     * Starts a fresh payment for an order that is still awaiting one.
     *
     * The gateway page can be closed, expired or never reached — before
     * this the order was simply stranded, since nothing else in the app
     * could re-open a payment.
     */
    public function pay(Order $order)
    {
        if (! $this->isPayable($order)) {
            throw ValidationException::withMessages([
                'payment' => 'Pesanan ini tidak bisa dibayar lagi.',
            ]);
        }

        $gatewayKey = $this->payment->activeGatewayKey();

        if (! $gatewayKey) {
            throw ValidationException::withMessages([
                'payment' => 'Metode pembayaran sedang tidak tersedia. Silakan hubungi kami.',
            ]);
        }

        $confirmationUrl = URL::signedRoute('order.show', ['order' => $order->order_number]);

        try {
            $result = $this->payment->initiate($order, $gatewayKey, [
                'return_url' => $confirmationUrl,
                'callback_url' => route('payment.callback', ['gateway' => $gatewayKey]),
            ]);
        } catch (Throwable $e) {
            Log::error("Payment retry failed for order [{$order->order_number}] via gateway [{$gatewayKey}]: {$e->getMessage()}");

            throw ValidationException::withMessages([
                'payment' => 'Halaman pembayaran sedang tidak bisa dibuka. Silakan coba lagi sesaat lagi.',
            ]);
        }

        if ($result['redirect_url']) {
            return Inertia::location($result['redirect_url']);
        }

        return redirect()->to($confirmationUrl);
    }

    /**
     * Awaiting payment, and not already settled or written off.
     */
    private function isPayable(Order $order): bool
    {
        return $order->status === Order::STATUS_PENDING
            && $order->payment_status !== PaymentStatus::PAID;
    }
}
