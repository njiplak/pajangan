<?php

use App\Models\Order;
use App\Models\Product;
use Illuminate\Support\Facades\Http;

function checkoutPayload(): array
{
    return [
        'customer_name' => 'Budi Santoso',
        'customer_email' => 'budi@example.com',
        'customer_phone' => '081234567890',
        'shipping_address' => 'Jl. Merdeka No. 1',
        'shipping_city' => 'Jayapura',
        'shipping_province' => 'Papua',
        'shipping_postal_code' => '99111',
        'destination_area_id' => 'IDNP1IDNC1IDND1IDZ10110',
        'destination_area_name' => 'Jayapura, Papua',
        'courier_code' => 'jne',
        'courier_service_code' => 'reg',
        'notes' => null,
    ];
}

/**
 * Configures Biteship and fakes a single matching rate (JNE REG, 15000) so
 * checkout's server-side rate re-verification succeeds.
 */
function fakeBiteshipRate(int $price = 15000): void
{
    config([
        'services.biteship.api_key' => 'biteship_test.abc',
        'services.biteship.base_url' => 'https://api.biteship.com',
        'services.biteship.origin_area_id' => 'IDNP6IDNC148IDND854IDZ10730',
        'services.biteship.couriers' => 'jne,jnt',
    ]);

    Http::fake([
        'api.biteship.com/v1/rates/couriers' => Http::response([
            'success' => true,
            'pricing' => [
                [
                    'courier_code' => 'jne',
                    'courier_name' => 'JNE',
                    'courier_service_code' => 'reg',
                    'courier_service_name' => 'Regular',
                    'price' => $price,
                    'duration' => '2-3 days',
                    'available_collection_method' => ['pickup', 'drop_off'],
                ],
            ],
        ], 200),
    ]);
}

test('guest can add a product to the cart and see it on the cart page', function () {
    $product = Product::create([
        'name' => 'Kopi Test',
        'price' => 50000,
        'stock' => 10,
        'is_active' => true,
    ]);

    $this->post(route('cart.store'), [
        'product_id' => $product->id,
        'quantity' => 2,
    ])->assertRedirect();

    $response = $this->get(route('cart.index'));

    $response->assertOk();
    $response->assertInertia(fn ($page) => $page
        ->component('storefront/cart/index')
        ->where('cart.items.0.product_id', $product->id)
        ->where('cart.items.0.quantity', 2)
        ->where('cart.subtotal', 100000));
});

test('checkout creates an order, decrements stock, and clears the cart', function () {
    fakeBiteshipRate();

    $product = Product::create([
        'name' => 'Noken Test',
        'price' => 150000,
        'stock' => 5,
        'weight_gram' => 500,
        'is_active' => true,
    ]);

    $this->post(route('cart.store'), [
        'product_id' => $product->id,
        'quantity' => 3,
    ]);

    $response = $this->post(route('checkout.store'), checkoutPayload());

    $order = Order::first();

    expect($order)->not->toBeNull();
    expect($order->status)->toBe(Order::STATUS_PENDING);
    expect($order->subtotal)->toBe(450000);
    expect($order->shipping_cost)->toBe(15000);
    expect($order->total)->toBe(465000);
    expect($order->courier_code)->toBe('jne');
    expect($order->courier_service)->toBe('reg');
    expect($order->shipping_area_id)->toBe('IDNP1IDNC1IDND1IDZ10110');
    expect($order->items)->toHaveCount(1);
    expect($order->items->first()->product_id)->toBe($product->id);
    expect($order->items->first()->quantity)->toBe(3);

    Http::assertSent(function ($request) {
        if (! str_contains($request->url(), '/v1/rates/couriers')) {
            return false;
        }

        expect($request['items'][0]['weight'])->toBe(1500);

        return true;
    });

    $product->refresh();
    expect($product->stock)->toBe(2);

    $response->assertRedirect();
    expect($response->headers->get('Location'))->toContain($order->order_number);
    expect($response->headers->get('Location'))->toContain('signature=');

    // Cart should be empty after a successful checkout.
    $cartResponse = $this->get(route('cart.index'));
    $cartResponse->assertInertia(fn ($page) => $page->where('cart.items', []));
});

test('checkout rejects an order that exceeds available stock', function () {
    $product = Product::create([
        'name' => 'Madu Test',
        'price' => 90000,
        'stock' => 2,
        'is_active' => true,
    ]);

    $this->post(route('cart.store'), [
        'product_id' => $product->id,
        'quantity' => 2,
    ]);

    // Someone else buys the remaining stock in between add-to-cart and checkout.
    $product->decrement('stock', 2);
    expect($product->fresh()->stock)->toBe(0);

    $response = $this->post(route('checkout.store'), checkoutPayload());

    $response->assertSessionHasErrors('cart');
    expect(Order::count())->toBe(0);

    // Stock must not be double-decremented or otherwise mutated by the failed attempt.
    expect($product->fresh()->stock)->toBe(0);
});

test('checkout redirects straight to the active gateway payment page when one is configured', function () {
    fakeBiteshipRate();

    $product = Product::create([
        'name' => 'Noken Test',
        'price' => 150000,
        'stock' => 5,
        'is_active' => true,
    ]);

    $this->post(route('cart.store'), ['product_id' => $product->id, 'quantity' => 1]);

    app(\App\Service\Payment\PaymentGatewayManager::class)->register(new class implements \App\Contract\Payment\PaymentGatewayContract
    {
        public function key(): string
        {
            return 'checkout-fake';
        }

        public function label(): string
        {
            return 'Checkout Fake';
        }

        public function isConfigured(): bool
        {
            return true;
        }

        public function listChannels(): array
        {
            return [];
        }

        public function createTransaction(\App\Models\Order $order, int $amount, array $options = []): array
        {
            return [
                'reference' => 'FAKE-'.$order->order_number,
                'redirect_url' => 'https://fake-gateway.test/pay/'.$order->order_number,
                'token' => null,
                'expires_at' => null,
                'raw' => ['amount' => $amount],
            ];
        }

        public function verifyNotification(string $rawBody, array $headers = []): bool
        {
            return true;
        }

        public function parseNotification(array $payload): array
        {
            return ['reference' => '', 'status' => \App\Contract\Payment\PaymentStatus::PENDING, 'amount' => 0, 'paid_at' => null, 'raw' => []];
        }

        public function getStatus(string $reference): array
        {
            return ['reference' => $reference, 'status' => \App\Contract\Payment\PaymentStatus::PENDING, 'amount' => 0, 'paid_at' => null, 'raw' => []];
        }
    });

    \App\Models\Setting::create(['key' => 'payment_active_gateway', 'value' => 'checkout-fake']);

    $response = $this->post(route('checkout.store'), checkoutPayload());

    $order = Order::first();
    $response->assertRedirect('https://fake-gateway.test/pay/'.$order->order_number);

    expect($order->payment_gateway)->toBe('checkout-fake');
    expect($order->payment_reference)->toBe('FAKE-'.$order->order_number);
    expect($order->payment_status)->toBe(\App\Contract\Payment\PaymentStatus::PENDING);
});

test('checkout falls back to the confirmation page without losing the order when the gateway call fails', function () {
    fakeBiteshipRate();

    $product = Product::create([
        'name' => 'Noken Test',
        'price' => 150000,
        'stock' => 5,
        'is_active' => true,
    ]);

    $this->post(route('cart.store'), ['product_id' => $product->id, 'quantity' => 1]);

    app(\App\Service\Payment\PaymentGatewayManager::class)->register(new class implements \App\Contract\Payment\PaymentGatewayContract
    {
        public function key(): string
        {
            return 'checkout-fake-broken';
        }

        public function label(): string
        {
            return 'Checkout Fake Broken';
        }

        public function isConfigured(): bool
        {
            return true;
        }

        public function listChannels(): array
        {
            return [];
        }

        public function createTransaction(\App\Models\Order $order, int $amount, array $options = []): array
        {
            throw new \RuntimeException('gateway is down');
        }

        public function verifyNotification(string $rawBody, array $headers = []): bool
        {
            return true;
        }

        public function parseNotification(array $payload): array
        {
            return ['reference' => '', 'status' => \App\Contract\Payment\PaymentStatus::PENDING, 'amount' => 0, 'paid_at' => null, 'raw' => []];
        }

        public function getStatus(string $reference): array
        {
            return ['reference' => $reference, 'status' => \App\Contract\Payment\PaymentStatus::PENDING, 'amount' => 0, 'paid_at' => null, 'raw' => []];
        }
    });

    \App\Models\Setting::create(['key' => 'payment_active_gateway', 'value' => 'checkout-fake-broken']);

    $response = $this->post(route('checkout.store'), checkoutPayload());

    $order = Order::first();
    expect($order)->not->toBeNull();
    expect($order->status)->toBe(Order::STATUS_PENDING);

    $response->assertRedirect();
    expect($response->headers->get('Location'))->toContain($order->order_number);

    // Stock must still be decremented — the order itself is real, only the
    // payment kickoff failed.
    expect($product->fresh()->stock)->toBe(4);
});

test('checkout keeps the order without a shipping cost when the selected courier is no longer in the fresh rate quote', function () {
    config([
        'services.biteship.api_key' => 'biteship_test.abc',
        'services.biteship.base_url' => 'https://api.biteship.com',
        'services.biteship.origin_area_id' => 'IDNP6IDNC148IDND854IDZ10730',
        'services.biteship.couriers' => 'jne,jnt',
    ]);

    Http::fake([
        // No 'jne'/'reg' entry, so the client's selected courier can't be
        // matched against this fresh quote.
        'api.biteship.com/v1/rates/couriers' => Http::response([
            'success' => true,
            'pricing' => [
                [
                    'courier_code' => 'jnt',
                    'courier_name' => 'J&T',
                    'courier_service_code' => 'ez',
                    'courier_service_name' => 'Economy',
                    'price' => 12000,
                    'duration' => '3-4 days',
                    'available_collection_method' => ['drop_off'],
                ],
            ],
        ], 200),
    ]);

    $product = Product::create([
        'name' => 'Noken Test',
        'price' => 150000,
        'stock' => 5,
        'is_active' => true,
    ]);

    $this->post(route('cart.store'), ['product_id' => $product->id, 'quantity' => 1]);

    $response = $this->post(route('checkout.store'), checkoutPayload());

    $order = Order::first();
    expect($order)->not->toBeNull();
    expect($order->status)->toBe(Order::STATUS_PENDING);
    expect($order->shipping_cost)->toBeNull();
    expect($order->total)->toBe($order->subtotal);

    $response->assertRedirect();
    expect($response->headers->get('Location'))->toContain($order->order_number);

    // Stock must still be decremented — the order itself is real, only
    // shipping verification failed.
    expect($product->fresh()->stock)->toBe(4);
});

test('order confirmation page requires a valid signature', function () {
    $order = Order::create([
        'order_number' => 'ORD-TEST-000001',
        'customer_name' => 'Budi',
        'customer_email' => 'budi@example.com',
        'customer_phone' => '08123',
        'shipping_address' => 'Jl. Test',
        'shipping_city' => 'Jayapura',
        'shipping_province' => 'Papua',
        'status' => Order::STATUS_PENDING,
        'subtotal' => 10000,
        'total' => 10000,
    ]);

    $this->get(route('order.show', ['order' => $order->order_number]))
        ->assertForbidden();

    $signedUrl = \Illuminate\Support\Facades\URL::signedRoute('order.show', ['order' => $order->order_number]);

    $this->get($signedUrl)->assertOk();
});
