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
            // An explicit shape, not the model: toArray() would ship the raw
            // gateway payload, the stock draw and internal references to
            // anyone holding the link.
            'order' => $this->present($order),
            'timeline' => $this->timeline($order),
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
     * @return array<string, mixed>
     */
    private function present(Order $order): array
    {
        return [
            'order_number' => $order->order_number,
            'status' => $order->status,
            'created_at' => $order->created_at?->toIso8601String(),
            'paid_at' => $order->paid_at?->toIso8601String(),
            'customer_name' => $order->customer_name,
            'customer_email' => $order->customer_email,
            'customer_phone' => $order->customer_phone,
            'shipping_address' => $order->shipping_address,
            'shipping_city' => $order->shipping_city,
            'shipping_province' => $order->shipping_province,
            'shipping_postal_code' => $order->shipping_postal_code,
            'courier_name' => $order->courier_name,
            'courier_service' => $order->courier_service,
            'tracking_number' => $order->tracking_number,
            'subtotal' => (int) $order->subtotal,
            'shipping_cost' => (int) ($order->shipping_cost ?? 0),
            'admin_fee' => (int) ($order->admin_fee ?? 0),
            'total' => (int) $order->total,
            'items' => $order->items->map(fn ($item) => [
                'id' => $item->id,
                'product_name' => $item->product_name,
                'quantity' => $item->quantity,
                'subtotal' => (int) $item->subtotal,
            ])->values(),
        ];
    }

    /**
     * The fulfilment steps, each marked done or not, so the page can show
     * how far the order has come rather than a single status word.
     *
     * @return array<int, array{key: string, label: string, done: bool}>
     */
    private function timeline(Order $order): array
    {
        if ($order->status === Order::STATUS_CANCELLED) {
            return [
                ['key' => 'placed', 'label' => 'Pesanan dibuat', 'done' => true],
                ['key' => 'cancelled', 'label' => 'Pesanan dibatalkan', 'done' => true],
            ];
        }

        $reached = array_search($order->status, [
            Order::STATUS_PENDING,
            Order::STATUS_PAID,
            Order::STATUS_PROCESSING,
            Order::STATUS_SHIPPED,
            Order::STATUS_COMPLETED,
        ], true);

        $reached = $reached === false ? 0 : $reached;

        $steps = [
            ['key' => 'placed', 'label' => 'Pesanan dibuat'],
            ['key' => 'paid', 'label' => 'Pembayaran diterima'],
            ['key' => 'processing', 'label' => 'Pesanan disiapkan'],
            ['key' => 'shipped', 'label' => 'Dalam perjalanan'],
            ['key' => 'completed', 'label' => 'Sampai di tujuan'],
        ];

        return array_map(
            fn (array $step, int $index) => $step + ['done' => $index <= $reached],
            $steps,
            array_keys($steps),
        );
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
