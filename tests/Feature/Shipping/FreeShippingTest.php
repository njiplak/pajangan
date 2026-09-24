<?php

use App\Contract\Payment\PaymentGatewayContract;
use App\Contract\Payment\PaymentStatus;
use App\Models\Order;
use App\Models\Product;
use App\Models\Setting;
use App\Service\Payment\PaymentGatewayManager;
use Illuminate\Support\Facades\Http;

function freeShipRule(int $min, int $cap = 0): void
{
    Setting::updateOrCreate(['key' => 'free_shipping_min_subtotal'], ['value' => (string) $min]);
    Setting::updateOrCreate(['key' => 'free_shipping_max_subsidy'], ['value' => (string) $cap]);
}

function freeShipRate(int $price): void
{
    config([
        'services.biteship.api_key' => 'biteship_test.abc',
        'services.biteship.base_url' => 'https://api.biteship.com',
        'services.biteship.origin_area_id' => 'ORIGIN-1',
        'services.biteship.couriers' => 'jne',
    ]);
    Http::fake(['api.biteship.com/v1/rates/couriers' => Http::response(['success' => true, 'pricing' => [[
        'courier_code' => 'jne', 'courier_name' => 'JNE', 'courier_service_code' => 'reg',
        'courier_service_name' => 'Regular', 'price' => $price, 'duration' => '3-5 days',
        'available_collection_method' => ['pickup'],
    ]]], 200)]);
}

function freeShipCheckout(int $price, int $qty): Order
{
    $product = Product::create(['name' => 'Kopi '.uniqid(), 'price' => $price, 'stock' => 50, 'weight_gram' => 250, 'is_active' => true]);
    test()->post(route('cart.store'), ['product_id' => $product->id, 'quantity' => $qty]);
    test()->post(route('checkout.store'), [
        'customer_name' => 'A', 'customer_email' => 'a@example.com', 'customer_phone' => '0812',
        'shipping_address' => 'Jl. Test', 'shipping_city' => 'Jayapura', 'shipping_province' => 'Papua',
        'shipping_postal_code' => '99111', 'destination_area_id' => 'AREA-1', 'destination_area_name' => 'Jayapura',
        'courier_code' => 'jne', 'courier_service_code' => 'reg', 'notes' => null,
    ]);

    return Order::latest('id')->first();
}

function registerAmountGateway(): void
{
    app(PaymentGatewayManager::class)->register(new class implements PaymentGatewayContract
    {
        public static ?int $charged = null;

        public function key(): string
        {
            return 'amountfake';
        }

        public function label(): string
        {
            return 'Amount Fake';
        }

        public function isConfigured(): bool
        {
            return true;
        }

        public function listChannels(): array
        {
            return [];
        }

        public function createTransaction(Order $order, int $amount, array $options = []): array
        {
            return ['reference' => 'AMT-'.$order->order_number, 'redirect_url' => null, 'token' => null, 'expires_at' => null, 'raw' => ['amount' => $amount]];
        }

        public function verifyNotification(string $rawBody, array $headers = []): bool
        {
            return true;
        }

        public function parseNotification(array $payload): array
        {
            return ['reference' => '', 'status' => PaymentStatus::PENDING, 'paid_at' => null, 'raw' => []];
        }

        public function getStatus(string $reference): array
        {
            return ['reference' => $reference, 'status' => PaymentStatus::PENDING, 'amount' => 0, 'paid_at' => null, 'raw' => []];
        }
    });
    Setting::updateOrCreate(['key' => 'payment_active_gateway'], ['value' => 'amountfake']);
}

test('free shipping is off by default: the customer pays ongkir', function () {
    freeShipRate(40000);

    $order = freeShipCheckout(60000, 5);

    expect($order->shipping_discount)->toBe(0);
    expect($order->total)->toBe(300000 + 40000);
});

test('an order above the threshold ships free', function () {
    freeShipRule(250000);
    freeShipRate(40000);

    $order = freeShipCheckout(60000, 5);

    expect($order->shipping_cost)->toBe(40000);
    expect($order->shipping_discount)->toBe(40000);
    expect($order->total)->toBe(300000);
});

test('an order below the threshold pays ongkir', function () {
    freeShipRule(250000);
    freeShipRate(40000);

    $order = freeShipCheckout(60000, 4);

    expect($order->shipping_discount)->toBe(0);
    expect($order->total)->toBe(240000 + 40000);
});

test('an order exactly at the threshold qualifies', function () {
    freeShipRule(300000);
    freeShipRate(40000);

    expect(freeShipCheckout(60000, 5)->shipping_discount)->toBe(40000);
});

test('the subsidy cap limits what the store absorbs', function () {
    freeShipRule(250000, cap: 25000);
    freeShipRate(40000);

    $order = freeShipCheckout(60000, 5);

    expect($order->shipping_discount)->toBe(25000);
    expect($order->total)->toBe(300000 + 40000 - 25000);
});

test('the gateway is charged the discounted total, not full ongkir', function () {
    registerAmountGateway();
    freeShipRule(250000);
    freeShipRate(40000);

    $order = freeShipCheckout(60000, 5);

    // PaymentService rewrites total from its own formula; it must agree.
    expect($order->total)->toBe(300000);
    expect($order->payment_payload['amount'])->toBe(300000);
});

test('the order page and email show the free-shipping line', function () {
    freeShipRule(250000);
    freeShipRate(40000);

    $order = freeShipCheckout(60000, 5);

    $this->get(\Illuminate\Support\Facades\URL::signedRoute('order.show', ['order' => $order->order_number]))
        ->assertInertia(fn ($page) => $page
            ->where('order.shipping_cost', 40000)
            ->where('order.shipping_discount', 40000)
            ->where('order.total', 300000));

    $html = (new \App\Mail\OrderPlacedMail($order->load('items')))->render();
    expect($html)->toContain('Gratis ongkir');
});
