<?php

use App\Contract\Payment\PaymentStatus;
use App\Models\Order;
use App\Models\OrderItem;
use App\Service\Payment\MidtransService;
use Illuminate\Support\Facades\Http;

function midtransTestOrder(): Order
{
    $order = Order::create([
        'order_number' => 'ORD-MIDTRANS-001',
        'customer_name' => 'Budi',
        'customer_email' => 'budi@example.com',
        'customer_phone' => '08123456789',
        'shipping_address' => 'Jl. Test',
        'shipping_city' => 'Jayapura',
        'shipping_province' => 'Papua',
        'status' => Order::STATUS_PENDING,
        'subtotal' => 100000,
        'total' => 100000,
    ]);

    OrderItem::create([
        'order_id' => $order->id,
        'product_id' => null,
        'product_name' => 'Kopi Test',
        'unit_price' => 50000,
        'quantity' => 2,
        'subtotal' => 100000,
    ]);

    return $order;
}

test('createTransaction uses Basic Auth with the server key and reconciles gross_amount to item_details', function () {
    config(['services.midtrans.server_key' => 'SB-Mid-server-abc', 'services.midtrans.sandbox' => true]);

    Http::fake([
        'app.sandbox.midtrans.com/*' => Http::response([
            'token' => 'snap-token-123',
            'redirect_url' => 'https://app.sandbox.midtrans.com/snap/v3/redirection/snap-token-123',
        ], 201),
    ]);

    $order = midtransTestOrder();
    $service = app(MidtransService::class);

    $result = $service->createTransaction($order, 105000, []);

    expect($result['token'])->toBe('snap-token-123');
    expect($result['redirect_url'])->toContain('snap-token-123');

    Http::assertSent(function ($request) {
        expect($request->url())->toContain('/snap/v1/transactions');
        expect($request->hasHeader('Authorization', 'Basic '.base64_encode('SB-Mid-server-abc:')))->toBeTrue();
        expect($request['transaction_details']['order_id'])->toBe('ORD-MIDTRANS-001');
        expect($request['transaction_details']['gross_amount'])->toBe(105000);

        $items = $request['item_details'];
        $itemsTotal = array_sum(array_map(fn ($i) => $i['price'] * $i['quantity'], $items));
        expect($itemsTotal)->toBe(105000);

        return true;
    });
});

test('verifyNotification recomputes the SHA512 signature_key formula and rejects a bad one', function () {
    config(['services.midtrans.server_key' => 'server-key-xyz']);

    $service = app(MidtransService::class);

    $payload = [
        'order_id' => 'ORD-MIDTRANS-001',
        'status_code' => '200',
        'gross_amount' => '105000.00',
    ];
    $payload['signature_key'] = hash('sha512', $payload['order_id'].$payload['status_code'].$payload['gross_amount'].'server-key-xyz');

    expect($service->verifyNotification(json_encode($payload)))->toBeTrue();

    $payload['signature_key'] = 'tampered';
    expect($service->verifyNotification(json_encode($payload)))->toBeFalse();
});

test('parseNotification maps capture+accept to paid but capture+challenge to pending', function () {
    $service = app(MidtransService::class);

    $paid = $service->parseNotification([
        'order_id' => 'ORD-1', 'transaction_status' => 'capture', 'fraud_status' => 'accept', 'gross_amount' => '105000.00',
    ]);
    expect($paid['status'])->toBe(PaymentStatus::PAID);
    expect($paid['amount'])->toBe(105000);

    $pending = $service->parseNotification([
        'order_id' => 'ORD-1', 'transaction_status' => 'capture', 'fraud_status' => 'challenge', 'gross_amount' => '105000.00',
    ]);
    expect($pending['status'])->toBe(PaymentStatus::PENDING);

    $settlement = $service->parseNotification([
        'order_id' => 'ORD-1', 'transaction_status' => 'settlement', 'gross_amount' => '105000.00',
    ]);
    expect($settlement['status'])->toBe(PaymentStatus::PAID);

    $expired = $service->parseNotification([
        'order_id' => 'ORD-1', 'transaction_status' => 'expire', 'gross_amount' => '105000.00',
    ]);
    expect($expired['status'])->toBe(PaymentStatus::EXPIRED);
});
