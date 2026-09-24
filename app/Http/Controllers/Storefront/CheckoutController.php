<?php

namespace App\Http\Controllers\Storefront;

use App\Contract\Notification\OrderNotifierContract;
use App\Http\Controllers\Controller;
use App\Http\Requests\CheckoutRequest;
use App\Models\BundleItem;
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
use Symfony\Component\HttpFoundation\Response;
use Throwable;

class CheckoutController extends Controller
{
    public function __construct(
        private readonly CartService $cart,
        private readonly PaymentService $payment,
        private readonly ShippingProviderManager $shipping,
        private readonly OrderNotifierContract $notifier,
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

        // Priced before a single row is written. An order whose shipping
        // could not be quoted must not exist at all: the old flow created
        // it first and, when the quote failed, silently left `total` at
        // the bare subtotal and charged the customer no ongkir at all.
        $quoted = $this->quoteSelectedRate($request);

        $result = DB::transaction(function () use ($rawCart, $request, $quoted) {
            $cartIds = array_keys($rawCart);

            // A bundle sells its components' stock, so the rows to lock are
            // the cart's own products plus everything those bundles contain.
            $componentIds = BundleItem::query()
                ->whereIn('bundle_id', $cartIds)
                ->pluck('product_id')
                ->all();

            // Lock the involved product rows so a concurrent checkout can't
            // oversell the same stock between our read and our write.
            $products = Product::query()
                ->whereIn('id', array_unique(array_merge($cartIds, $componentIds)))
                ->lockForUpdate()
                ->get()
                ->keyBy('id');

            // Re-read composition now that the rows are held: a bundle
            // edited between the two reads must not slip past this check.
            $bundleItems = BundleItem::query()->whereIn('bundle_id', $cartIds)->get();

            foreach ($bundleItems as $bundleItem) {
                if (! $products->has($bundleItem->product_id)) {
                    throw ValidationException::withMessages([
                        'cart' => 'Isi paket di keranjang Anda baru saja berubah. Silakan periksa kembali keranjang Anda.',
                    ]);
                }
            }

            // Point each bundle at the locked component rows, so
            // availableStock() reads what we hold rather than issuing a
            // fresh unlocked query through the relation.
            $itemsByBundle = $bundleItems->groupBy('bundle_id');

            foreach ($products as $product) {
                if ($product->is_bundle) {
                    $items = $itemsByBundle->get($product->id, collect())->each(
                        fn (BundleItem $bundleItem) => $bundleItem->setRelation('product', $products->get($bundleItem->product_id))
                    );

                    $product->setRelation('bundleItems', $items);
                }
            }

            $lineItems = [];
            $subtotal = 0;
            $weightGrams = 0;
            $stockDraw = [];

            foreach ($rawCart as $productId => $quantity) {
                $product = $products->get($productId);

                if (! $product || ! $product->is_active) {
                    throw ValidationException::withMessages([
                        'cart' => 'Salah satu produk di keranjang Anda sudah tidak tersedia. Silakan periksa kembali keranjang Anda.',
                    ]);
                }

                $available = $product->availableStock();

                if ($available < $quantity) {
                    throw ValidationException::withMessages([
                        'cart' => "Stok {$product->name} tinggal {$available}. Silakan sesuaikan jumlah di keranjang Anda.",
                    ]);
                }

                $unitPrice = $product->effectivePrice();
                $lineSubtotal = $unitPrice * $quantity;
                $subtotal += $lineSubtotal;
                $weightGrams += $product->shippingWeightGram() * $quantity;

                if ($product->is_bundle) {
                    foreach ($product->bundleItems as $bundleItem) {
                        $stockDraw[$bundleItem->product_id] =
                            ($stockDraw[$bundleItem->product_id] ?? 0) + ($bundleItem->quantity * $quantity);
                    }
                } else {
                    $stockDraw[$productId] = ($stockDraw[$productId] ?? 0) + $quantity;
                }

                $lineItems[] = [
                    'product' => $product,
                    'quantity' => $quantity,
                    'unit_price' => $unitPrice,
                    'subtotal' => $lineSubtotal,
                ];
            }

            // Per-line checks aren't enough: a bundle and the component's
            // own listing can each pass alone yet exceed the stock together.
            foreach ($stockDraw as $componentId => $units) {
                $component = $products->get($componentId);

                if (! $component || $component->stock < $units) {
                    $name = $component->name ?? 'produk';

                    throw ValidationException::withMessages([
                        'cart' => "Stok {$name} tidak mencukupi untuk seluruh isi keranjang Anda. Silakan sesuaikan jumlah di keranjang Anda.",
                    ]);
                }
            }

            // The quote above priced a parcel of a particular weight. If the
            // authoritative weight differs, that quote is for a different
            // shipment and must not be charged.
            if ($weightGrams !== $quoted['weight_gram']) {
                throw ValidationException::withMessages([
                    'cart' => 'Isi keranjang Anda baru saja berubah. Silakan periksa kembali keranjang Anda.',
                ]);
            }

            $order = Order::create([
                // Null for guests; set when signed in so the order lands in
                // the account even if they typed a different email.
                'customer_id' => $request->user('customer')?->id,
                'order_number' => $this->generateOrderNumber(),
                'customer_name' => $request->validated('customer_name'),
                'customer_email' => $request->validated('customer_email'),
                'customer_phone' => $request->validated('customer_phone'),
                'shipping_address' => $request->validated('shipping_address'),
                'shipping_city' => $request->validated('shipping_city'),
                'shipping_province' => $request->validated('shipping_province'),
                'shipping_postal_code' => $request->validated('shipping_postal_code'),
                'notes' => $request->validated('notes'),
                // Recorded now so a later release returns exactly this,
                // whatever happens to the bundles in the meantime.
                'stock_draw' => $stockDraw,
                'status' => Order::STATUS_PENDING,
                'subtotal' => $subtotal,
                'shipping_cost' => $quoted['price'],
                'shipping_area_id' => $request->validated('destination_area_id'),
                'shipping_area_name' => $request->validated('destination_area_name'),
                'courier_code' => $quoted['courier_code'],
                'courier_name' => $quoted['courier_name'],
                'courier_service' => $quoted['courier_service_code'],
                'total' => $subtotal + $quoted['price'],
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
            }

            foreach ($stockDraw as $componentId => $units) {
                $products->get($componentId)->decrement('stock', $units);
            }

            return ['order' => $order, 'weight_gram' => $weightGrams];
        });

        $order = $result['order'];

        $this->cart->clear();

        $confirmationUrl = URL::signedRoute('order.show', ['order' => $order->order_number]);

        $gatewayKey = $this->payment->activeGatewayKey();
        $gatewayRedirect = null;

        if ($gatewayKey) {
            try {
                $result = $this->payment->initiate($order, $gatewayKey, [
                    'return_url' => $confirmationUrl,
                    'callback_url' => route('payment.callback', ['gateway' => $gatewayKey]),
                ]);

                $gatewayRedirect = $result['redirect_url'];
            } catch (Throwable $e) {
                // The order (and its stock decrement) already exists and
                // must not be lost just because the gateway call failed.
                // Fall through to the confirmation page; the order stays
                // pending and can be retried/handled manually.
                Log::error("Payment initiation failed for order [{$order->order_number}] via gateway [{$gatewayKey}]: {$e->getMessage()}");
            }
        }

        // Sent after the gateway call because initiate() is what settles
        // the admin fee and the final total — mailing before it would
        // quote the customer a number we are not charging. Sent on both
        // paths: a customer redirected to a payment page still needs the
        // link back to their order.
        $this->notifier->orderPlaced($order->refresh());

        if ($gatewayRedirect) {
            return Inertia::location($gatewayRedirect);
        }

        return redirect()->to($confirmationUrl);
    }

    /**
     * Re-quotes the courier the customer picked and returns that rate, or
     * fails the checkout. Refusing to sell is the right outcome here: we
     * cannot charge for a shipment nobody will price.
     *
     * @return array{courier_code: string, courier_name: string, courier_service_code: string, price: int, weight_gram: int}
     */
    private function quoteSelectedRate(CheckoutRequest $request): array
    {
        $weightGram = $this->cart->totalWeightGrams();

        try {
            $rates = $this->shipping->resolve('biteship')->quoteRates([
                'destination_area_id' => $request->validated('destination_area_id'),
                'weight_gram' => $weightGram,
                'item_value' => $this->cart->summary()['subtotal'],
            ]);
        } catch (Throwable $e) {
            Log::error("Shipping rate quote failed during checkout: {$e->getMessage()}");

            throw ValidationException::withMessages([
                'cart' => 'Ongkos kirim sedang tidak bisa dihitung. Silakan coba lagi sesaat lagi.',
            ]);
        }

        $match = collect($rates)->first(
            fn (array $rate) => $rate['courier_code'] === $request->validated('courier_code')
                && $rate['courier_service_code'] === $request->validated('courier_service_code')
        );

        if (! $match) {
            throw ValidationException::withMessages([
                'courier_code' => 'Layanan kurir yang Anda pilih sudah tidak tersedia. Silakan pilih ulang.',
            ]);
        }

        return [
            'courier_code' => $match['courier_code'],
            'courier_name' => $match['courier_name'],
            'courier_service_code' => $match['courier_service_code'],
            'price' => (int) $match['price'],
            'weight_gram' => $weightGram,
        ];
    }

    private function generateOrderNumber(): string
    {
        do {
            $candidate = 'ORD-'.now()->format('Ymd').'-'.Str::upper(Str::random(6));
        } while (Order::query()->where('order_number', $candidate)->exists());

        return $candidate;
    }
}
