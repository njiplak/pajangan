<?php

namespace App\Http\Controllers\Storefront;

use App\Contract\Notification\OrderNotifierContract;
use App\Http\Controllers\Controller;
use App\Http\Controllers\Customer\AddressController;
use App\Http\Requests\CheckoutRequest;
use App\Models\Order;
use App\Models\StockMovement;
use App\Service\Cart\CartService;
use App\Service\Customer\AddressBook;
use App\Service\Order\OrderActivityLog;
use App\Service\Order\StockDrawException;
use App\Service\Order\StockDrawPlanner;
use App\Service\Payment\PaymentService;
use App\Service\Shipping\FreeShipping;
use App\Service\Shipping\ShippingProviderManager;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\URL;
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
        private readonly AddressBook $addressBook,
        private readonly FreeShipping $freeShipping,
        private readonly StockDrawPlanner $planner,
        private readonly OrderActivityLog $activity,
    ) {}

    public function index()
    {
        $summary = $this->cart->summary();

        if (empty($summary['items'])) {
            return redirect()->route('cart.index');
        }

        $customer = request()->user('customer');

        return Inertia::render('storefront/checkout/index', [
            'cart' => $summary,
            // Signed-in customers get their details and address book so
            // checkout is a pick, not a retype. Guests get neither.
            'profile' => $customer ? [
                'name' => $customer->name,
                'email' => $customer->email,
                'phone' => $customer->phone,
            ] : null,
            'savedAddresses' => $customer
                ? $customer->addresses()->orderByDesc('is_default')->latest()->get()
                    ->map(fn ($address) => AddressController::present($address))->values()
                : [],
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

        // Checked before anything is written: an order nothing can ever pay
        // is not a sale, and stock must not be drawn down for it. Mirrors
        // the same guard on the payment-retry path in OrderLookupController.
        if (! $this->payment->activeGatewayKey()) {
            throw ValidationException::withMessages([
                'payment' => 'Metode pembayaran sedang tidak tersedia. Silakan hubungi kami.',
            ]);
        }

        // Priced before a single row is written. An order whose shipping
        // could not be quoted must not exist at all: the old flow created
        // it first and, when the quote failed, silently left `total` at
        // the bare subtotal and charged the customer no ongkir at all.
        $quoted = $this->quoteSelectedRate($request);

        $result = DB::transaction(function () use ($rawCart, $request, $quoted) {
            try {
                $plan = $this->planner->plan($rawCart);
            } catch (StockDrawException $e) {
                throw ValidationException::withMessages(['cart' => $this->cartMessage($e)]);
            }

            // The quote above priced a parcel of a particular weight. If the
            // authoritative weight differs, that quote is for a different
            // shipment and must not be charged.
            if ($plan['weight_gram'] !== $quoted['weight_gram']) {
                throw ValidationException::withMessages([
                    'cart' => 'Isi keranjang Anda baru saja berubah. Silakan periksa kembali keranjang Anda.',
                ]);
            }

            $subtotal = $plan['subtotal'];

            // Judged on the authoritative subtotal computed under lock.
            $shippingDiscount = $this->freeShipping->discountFor($subtotal, $quoted['price']);

            $order = Order::create([
                // Null for guests; set when signed in so the order lands in
                // the account even if they typed a different email.
                'customer_id' => $request->user('customer')?->id,
                'order_number' => Order::generateNumber(),
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
                'stock_draw' => $plan['draw'],
                'status' => Order::STATUS_PENDING,
                'subtotal' => $subtotal,
                'shipping_cost' => $quoted['price'],
                'shipping_discount' => $shippingDiscount,
                'shipping_area_id' => $request->validated('destination_area_id'),
                'shipping_area_name' => $request->validated('destination_area_name'),
                'courier_code' => $quoted['courier_code'],
                'courier_name' => $quoted['courier_name'],
                'courier_service' => $quoted['courier_service_code'],
                'total' => $subtotal + $quoted['price'] - $shippingDiscount,
            ]);

            $this->planner->apply($plan, $order, StockMovement::REASON_CHECKOUT);

            $this->activity->record($order, 'created', 'Pesanan dibuat dari toko online.');

            return ['order' => $order, 'weight_gram' => $plan['weight_gram']];
        });

        $order = $result['order'];

        $this->cart->clear();

        $this->rememberAddress($request);

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
     * Saves the typed address to the customer's book when they asked to.
     * Runs after the order is committed and never fails it: losing a
     * convenience is acceptable, losing the order is not.
     */
    private function rememberAddress(CheckoutRequest $request): void
    {
        $customer = $request->user('customer');

        if (! $customer || ! $request->boolean('save_address')) {
            return;
        }

        try {
            $this->addressBook->rememberFromCheckout($customer, [
                'label' => $request->validated('address_label'),
                'recipient_name' => $request->validated('customer_name'),
                'phone' => $request->validated('customer_phone'),
                'address' => $request->validated('shipping_address'),
                'city' => $request->validated('shipping_city'),
                'province' => $request->validated('shipping_province'),
                'postal_code' => $request->validated('shipping_postal_code'),
                'destination_area_id' => $request->validated('destination_area_id'),
                'destination_area_name' => $request->validated('destination_area_name'),
            ]);
        } catch (Throwable $e) {
            Log::error("Saving checkout address failed for customer [{$customer->id}]: {$e->getMessage()}");
        }
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

    private function cartMessage(StockDrawException $e): string
    {
        return match ($e->reason) {
            StockDrawException::BUNDLE_CHANGED => 'Isi paket di keranjang Anda baru saja berubah. Silakan periksa kembali keranjang Anda.',
            StockDrawException::UNAVAILABLE => 'Salah satu produk di keranjang Anda sudah tidak tersedia. Silakan periksa kembali keranjang Anda.',
            StockDrawException::LINE_SHORT => "Stok {$e->productName} tinggal {$e->available}. Silakan sesuaikan jumlah di keranjang Anda.",
            default => 'Stok '.($e->productName ?? 'produk').' tidak mencukupi untuk seluruh isi keranjang Anda. Silakan sesuaikan jumlah di keranjang Anda.',
        };
    }
}
