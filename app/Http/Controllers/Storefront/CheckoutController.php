<?php

namespace App\Http\Controllers\Storefront;

use App\Http\Controllers\Controller;
use App\Http\Requests\CheckoutRequest;
use App\Models\Order;
use App\Models\OrderItem;
use App\Models\Product;
use App\Service\Cart\CartService;
use App\Service\Payment\PaymentService;
use App\Service\Shipping\ShippingProviderManager;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\URL;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use RuntimeException;
use Symfony\Component\HttpFoundation\Response;
use Throwable;

class CheckoutController extends Controller
{
    public function __construct(
        private readonly CartService $cart,
        private readonly PaymentService $payment,
        private readonly ShippingProviderManager $shipping,
    ) {}

    public function index()
    {
        $summary = $this->cart->summary();

        if (empty($summary['items'])) {
            return redirect()->route('cart.index');
        }

        return Inertia::render('storefront/checkout/index', [
            'cart' => $summary,
        ]);
    }

    public function store(CheckoutRequest $request): Response
    {
        $rawCart = $this->cart->raw();

        if (empty($rawCart)) {
            throw ValidationException::withMessages([
                'cart' => 'Keranjang belanja Anda kosong.',
            ]);
        }

        $result = DB::transaction(function () use ($rawCart, $request) {
            // Lock the involved product rows so a concurrent checkout can't
            // oversell the same stock between our read and our write.
            $products = Product::query()
                ->whereIn('id', array_keys($rawCart))
                ->where('is_active', true)
                ->lockForUpdate()
                ->get()
                ->keyBy('id');

            $lineItems = [];
            $subtotal = 0;
            $weightGrams = 0;

            foreach ($rawCart as $productId => $quantity) {
                $product = $products->get($productId);

                if (! $product) {
                    throw ValidationException::withMessages([
                        'cart' => 'Salah satu produk di keranjang Anda sudah tidak tersedia. Silakan periksa kembali keranjang Anda.',
                    ]);
                }

                if ($product->stock < $quantity) {
                    throw ValidationException::withMessages([
                        'cart' => "Stok {$product->name} tinggal {$product->stock}. Silakan sesuaikan jumlah di keranjang Anda.",
                    ]);
                }

                $unitPrice = $product->effectivePrice();
                $lineSubtotal = $unitPrice * $quantity;
                $subtotal += $lineSubtotal;
                $weightGrams += $product->weight_gram * $quantity;

                $lineItems[] = [
                    'product' => $product,
                    'quantity' => $quantity,
                    'unit_price' => $unitPrice,
                    'subtotal' => $lineSubtotal,
                ];
            }

            $order = Order::create([
                'order_number' => $this->generateOrderNumber(),
                'customer_name' => $request->validated('customer_name'),
                'customer_email' => $request->validated('customer_email'),
                'customer_phone' => $request->validated('customer_phone'),
                'shipping_address' => $request->validated('shipping_address'),
                'shipping_city' => $request->validated('shipping_city'),
                'shipping_province' => $request->validated('shipping_province'),
                'shipping_postal_code' => $request->validated('shipping_postal_code'),
                'notes' => $request->validated('notes'),
                'status' => Order::STATUS_PENDING,
                'subtotal' => $subtotal,
                'total' => $subtotal,
            ]);

            foreach ($lineItems as $item) {
                OrderItem::create([
                    'order_id' => $order->id,
                    'product_id' => $item['product']->id,
                    'product_name' => $item['product']->name,
                    'unit_price' => $item['unit_price'],
                    'quantity' => $item['quantity'],
                    'subtotal' => $item['subtotal'],
                ]);

                $item['product']->decrement('stock', $item['quantity']);
            }

            return ['order' => $order, 'weight_gram' => $weightGrams];
        });

        $order = $result['order'];

        $this->cart->clear();

        try {
            $rates = $this->shipping->resolve('biteship')->quoteRates([
                'destination_area_id' => $request->validated('destination_area_id'),
                'weight_gram' => $result['weight_gram'],
                'item_value' => $order->subtotal,
            ]);

            $match = collect($rates)->first(
                fn (array $rate) => $rate['courier_code'] === $request->validated('courier_code')
                    && $rate['courier_service_code'] === $request->validated('courier_service_code')
            );

            if (! $match) {
                throw new RuntimeException('Selected courier/service is no longer available in the fresh rate quote.');
            }

            $order->update([
                'shipping_cost' => $match['price'],
                'shipping_area_id' => $request->validated('destination_area_id'),
                'shipping_area_name' => $request->validated('destination_area_name'),
                'courier_code' => $match['courier_code'],
                'courier_name' => $match['courier_name'],
                'courier_service' => $match['courier_service_code'],
                'total' => $order->subtotal + $match['price'],
            ]);
        } catch (Throwable $e) {
            // Same rationale as the payment-initiation catch below: the
            // order (and its stock decrement) already exists and must not
            // be lost just because the shipping rate could no longer be
            // verified. Staff can resolve shipping manually from the
            // backoffice, same as any order that has no shipment yet.
            Log::error("Shipping rate verification failed for order [{$order->order_number}]: {$e->getMessage()}");
        }

        $confirmationUrl = URL::signedRoute('order.show', ['order' => $order->order_number]);

        $gatewayKey = $this->payment->activeGatewayKey();

        if ($gatewayKey) {
            try {
                $result = $this->payment->initiate($order, $gatewayKey, [
                    'return_url' => $confirmationUrl,
                    'callback_url' => route('payment.callback', ['gateway' => $gatewayKey]),
                ]);

                if ($result['redirect_url']) {
                    return Inertia::location($result['redirect_url']);
                }
            } catch (Throwable $e) {
                // The order (and its stock decrement) already exists and
                // must not be lost just because the gateway call failed.
                // Fall through to the confirmation page; the order stays
                // pending and can be retried/handled manually.
                Log::error("Payment initiation failed for order [{$order->order_number}] via gateway [{$gatewayKey}]: {$e->getMessage()}");
            }
        }

        return redirect()->to($confirmationUrl);
    }

    private function generateOrderNumber(): string
    {
        do {
            $candidate = 'ORD-'.now()->format('Ymd').'-'.Str::upper(Str::random(6));
        } while (Order::query()->where('order_number', $candidate)->exists());

        return $candidate;
    }
}
