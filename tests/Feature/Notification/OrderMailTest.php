<?php

use App\Contract\Payment\PaymentStatus;
use App\Mail\OrderPlacedMail;
use App\Mail\OrderShippedMail;
use App\Mail\PaymentReceivedMail;
use App\Models\Order;
use App\Models\Product;
use App\Models\User;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Mail;
use Spatie\Permission\Models\Permission;

function mailCheckoutPayload(): array
{
    return [
        'customer_name' => 'Yohana Wenda',
        'customer_email' => 'yohana@example.com',
        'customer_phone' => '081234567890',
        'shipping_address' => 'Jl. Sentani No. 9',
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

function fakeMailRate(int $price = 15000): void
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
            'pricing' => [[
                'courier_code' => 'jne',
                'courier_name' => 'JNE',
                'courier_service_code' => 'reg',
                'courier_service_name' => 'Regular',
                'price' => $price,
                'duration' => '2-3 days',
                'available_collection_method' => ['pickup'],
            ]],
        ], 200),
    ]);
}

function mailProduct(): Product
{
    return Product::create([
        'name' => 'Kopi Wamena',
        'price' => 60000,
        'stock' => 10,
        'weight_gram' => 250,
        'is_active' => true,
    ]);
}

test('placing an order emails the customer a confirmation with the signed link', function () {
    Mail::fake();
    fakeMailRate();

    $product = mailProduct();
    $this->post(route('cart.store'), ['product_id' => $product->id, 'quantity' => 2]);
    $this->post(route('checkout.store'), mailCheckoutPayload());

    $order = Order::first();
    expect($order)->not->toBeNull();

    Mail::assertSent(OrderPlacedMail::class, function (OrderPlacedMail $mail) use ($order) {
        expect($mail->order->order_number)->toBe($order->order_number);

        $rendered = $mail->render();

        expect($rendered)->toContain($order->order_number);
        // The link back to the order is the whole point of this mail.
        expect($rendered)->toContain('signature=');

        return $mail->hasTo('yohana@example.com');
    });
});

test('the confirmation quotes the total actually charged, including ongkir', function () {
    Mail::fake();
    fakeMailRate(15000);

    $product = mailProduct();
    $this->post(route('cart.store'), ['product_id' => $product->id, 'quantity' => 2]);
    $this->post(route('checkout.store'), mailCheckoutPayload());

    $order = Order::first();
    expect($order->total)->toBe(135000);

    Mail::assertSent(OrderPlacedMail::class, function (OrderPlacedMail $mail) {
        // 120.000 + 15.000 ongkir
        expect($mail->render())->toContain('135.000');

        return true;
    });
});

test('a dead mail server does not cost the customer their order', function () {
    fakeMailRate();

    Mail::shouldReceive('to')->andThrow(new RuntimeException('smtp is down'));

    $product = mailProduct();
    $this->post(route('cart.store'), ['product_id' => $product->id, 'quantity' => 2]);

    $response = $this->post(route('checkout.store'), mailCheckoutPayload());

    $response->assertRedirect();
    expect(Order::count())->toBe(1);
    expect($product->fresh()->stock)->toBe(8);
});

test('no mail is sent when the customer has no email on file', function () {
    Mail::fake();

    $order = Order::create([
        'order_number' => 'ORD-TEST-1',
        'customer_name' => 'Tanpa Email',
        'customer_email' => '',
        'customer_phone' => '0812',
        'shipping_address' => 'Jl. Test',
        'shipping_city' => 'Jayapura',
        'shipping_province' => 'Papua',
        'status' => Order::STATUS_PENDING,
        'subtotal' => 1000,
        'total' => 1000,
    ]);

    app(\App\Contract\Notification\OrderNotifierContract::class)->orderPlaced($order);

    Mail::assertNothingSent();
});

test('a confirmed payment emails a receipt exactly once', function () {
    Mail::fake();

    $order = Order::create([
        'order_number' => 'ORD-TEST-2',
        'customer_name' => 'Yohana Wenda',
        'customer_email' => 'yohana@example.com',
        'customer_phone' => '0812',
        'shipping_address' => 'Jl. Test',
        'shipping_city' => 'Jayapura',
        'shipping_province' => 'Papua',
        'status' => Order::STATUS_PENDING,
        'subtotal' => 120000,
        'total' => 135000,
        'payment_gateway' => 'mailfake',
        'payment_reference' => 'REF-2',
        'payment_status' => PaymentStatus::PENDING,
    ]);

    app(\App\Service\Payment\PaymentGatewayManager::class)->register(new class implements \App\Contract\Payment\PaymentGatewayContract
    {
        public function key(): string
        {
            return 'mailfake';
        }

        public function label(): string
        {
            return 'Mail Fake';
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
            return ['reference' => 'REF-2', 'redirect_url' => null, 'token' => null, 'expires_at' => null, 'raw' => []];
        }

        public function verifyNotification(string $rawBody, array $headers = []): bool
        {
            return true;
        }

        public function parseNotification(array $payload): array
        {
            return [
                'reference' => 'REF-2',
                'status' => PaymentStatus::PAID,
                'paid_at' => now(),
                'raw' => $payload,
            ];
        }

        public function getStatus(string $reference): array
        {
            return [
                'reference' => $reference,
                'status' => PaymentStatus::PAID,
                'amount' => 135000,
                'paid_at' => null,
                'raw' => [],
            ];
        }
    });

    $this->postJson(route('payment.callback', ['gateway' => 'mailfake']), ['ok' => true])->assertOk();

    expect($order->fresh()->status)->toBe(Order::STATUS_PAID);
    Mail::assertSent(PaymentReceivedMail::class, 1);

    // A duplicate webhook must not send a second receipt.
    $this->postJson(route('payment.callback', ['gateway' => 'mailfake']), ['ok' => true])->assertOk();

    Mail::assertSent(PaymentReceivedMail::class, 1);
});

test('creating a shipment emails the tracking number', function () {
    Mail::fake();

    config([
        'services.biteship.api_key' => 'biteship_test.abc',
        'services.biteship.base_url' => 'https://api.biteship.com',
        'services.biteship.origin_area_id' => 'ORIGIN-1',
        'services.biteship.couriers' => 'jne',
        'services.biteship.sender_name' => 'UMKM Papua',
        'services.biteship.sender_phone' => '0812000',
        'services.biteship.sender_address' => 'Jl. Gudang 1',
    ]);

    Http::fake([
        'api.biteship.com/v1/orders' => Http::response([
            'success' => true,
            'id' => 'BTS-1',
            'status' => 'confirmed',
            'courier' => [
                'waybill_id' => 'JNE123456',
                'company' => 'jne',
                'name' => 'JNE',
                'type' => 'reg',
            ],
            'price' => 15000,
        ], 200),
    ]);

    $user = User::factory()->create();
    Permission::firstOrCreate(['name' => 'order.update', 'guard_name' => 'web']);
    $user->givePermissionTo('order.update');

    $order = Order::create([
        'order_number' => 'ORD-TEST-3',
        'customer_name' => 'Yohana Wenda',
        'customer_email' => 'yohana@example.com',
        'customer_phone' => '0812',
        'shipping_address' => 'Jl. Test',
        'shipping_city' => 'Jayapura',
        'shipping_province' => 'Papua',
        'status' => Order::STATUS_PAID,
        'subtotal' => 120000,
        'total' => 135000,
    ]);

    $this->actingAs($user)->post(route('backoffice.order.shipping-create', $order->id), [
        'destination_area_id' => 'AREA-1',
        'destination_area_name' => 'Jayapura',
        'weight_gram' => 500,
        'courier_code' => 'jne',
        'courier_service_code' => 'reg',
    ]);

    expect($order->fresh()->tracking_number)->toBe('JNE123456');

    Mail::assertSent(OrderShippedMail::class, function (OrderShippedMail $mail) {
        expect($mail->render())->toContain('JNE123456');

        return true;
    });
});
