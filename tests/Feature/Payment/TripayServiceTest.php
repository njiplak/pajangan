<?php

use App\Models\Order;
use App\Models\OrderItem;
use App\Service\Payment\TripayService;
use Illuminate\Support\Facades\Http;

function tripayTestOrder(): Order
{
    $order = Order::create([
        'order_number' => 'ORD-TRIPAY-001',
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

test('createTransaction sends a correctly signed request and reconciles the admin fee as a line item', function () {
    config([
        'services.tripay.merchant_code' => 'T0001',
        'services.tripay.api_key' => 'api-key',
        'services.tripay.private_key' => 'private-key',
        'services.tripay.sandbox' => true,
    ]);

    Http::fake([
        'tripay.co.id/*' => Http::response([
            'success' => true,
            'data' => [
                'reference' => 'DEV-abc123',
                'checkout_url' => 'https://tripay.co.id/checkout/DEV-abc123',
                'expired_time' => 1735689600,
            ],
        ], 200),
    ]);

    $order = tripayTestOrder();
    $service = app(TripayService::class);

    $result = $service->createTransaction($order, 105000, ['method' => 'BRIVA']);

    expect($result['reference'])->toBe('DEV-abc123');
    expect($result['redirect_url'])->toBe('https://tripay.co.id/checkout/DEV-abc123');

    Http::assertSent(function ($request) {
        $expectedSignature = hash_hmac('sha256', 'T0001'.'ORD-TRIPAY-001'.'105000', 'private-key');

        expect($request->url())->toContain('/transaction/create');
        expect($request['method'])->toBe('BRIVA');
        expect($request['amount'])->toBe(105000);
        expect($request['signature'])->toBe($expectedSignature);

        $items = $request['order_items'];
        expect($items)->toHaveCount(2);
        expect($items[1]['name'])->toBe('Biaya Admin');
        expect($items[1]['price'])->toBe(5000);

        $itemsTotal = array_sum(array_map(fn ($i) => $i['price'] * $i['quantity'], $items));
        expect($itemsTotal)->toBe(105000);

        return true;
    });
});

test('createTransaction requires a payment method', function () {
    $order = tripayTestOrder();
    $service = app(TripayService::class);

    $service->createTransaction($order, 100000, []);
})->throws(InvalidArgumentException::class);

test('verifyNotification accepts a correctly signed callback and rejects a tampered one', function () {
    config(['services.tripay.private_key' => 'private-key']);

    $service = app(TripayService::class);
    $rawBody = json_encode(['reference' => 'DEV-abc123', 'merchant_ref' => 'ORD-TRIPAY-001', 'status' => 'PAID', 'total_amount' => 105000]);
    $validSignature = hash_hmac('sha256', $rawBody, 'private-key');

    expect($service->verifyNotification($rawBody, ['x-callback-signature' => $validSignature]))->toBeTrue();
    expect($service->verifyNotification($rawBody, ['x-callback-signature' => 'wrong-signature']))->toBeFalse();
    expect($service->verifyNotification($rawBody, []))->toBeFalse();

    $tamperedBody = json_encode(['reference' => 'DEV-abc123', 'merchant_ref' => 'ORD-TRIPAY-001', 'status' => 'PAID', 'total_amount' => 999999999]);
    expect($service->verifyNotification($tamperedBody, ['x-callback-signature' => $validSignature]))->toBeFalse();
});

test('listChannels fetches the merchant payment-channel list and filters out inactive channels', function () {
    config(['services.tripay.api_key' => 'api-key', 'services.tripay.sandbox' => true]);

    Http::fake([
        'tripay.co.id/*' => Http::response([
            'success' => true,
            'data' => [
                [
                    'code' => 'BRIVA',
                    'name' => 'BRI Virtual Account',
                    'active' => true,
                    'total_fee' => ['flat' => 4250, 'percent' => '0.00'],
                ],
                [
                    'code' => 'QRIS',
                    'name' => 'QRIS',
                    'active' => true,
                    'total_fee' => ['flat' => 0, 'percent' => '0.7'],
                ],
                ['code' => 'OLD_CHANNEL', 'name' => 'Retired Channel', 'active' => false],
            ],
        ], 200),
    ]);

    $channels = app(TripayService::class)->listChannels();

    expect($channels)->toBe([
        ['code' => 'BRIVA', 'name' => 'BRI Virtual Account', 'fee_flat' => 4250, 'fee_percent' => 0.0],
        ['code' => 'QRIS', 'name' => 'QRIS', 'fee_flat' => 0, 'fee_percent' => 0.7],
    ]);

    Http::assertSent(fn ($request) => str_contains($request->url(), '/merchant/payment-channel'));
});

test('parseNotification maps Tripay statuses to normalized PaymentStatus values', function () {
    $service = app(TripayService::class);

    expect($service->parseNotification(['reference' => 'r', 'status' => 'PAID', 'total_amount' => 1000])['status'])
        ->toBe(\App\Contract\Payment\PaymentStatus::PAID);
    expect($service->parseNotification(['reference' => 'r', 'status' => 'EXPIRED', 'total_amount' => 1000])['status'])
        ->toBe(\App\Contract\Payment\PaymentStatus::EXPIRED);
    expect($service->parseNotification(['reference' => 'r', 'status' => 'REFUND', 'total_amount' => 1000])['status'])
        ->toBe(\App\Contract\Payment\PaymentStatus::REFUNDED);
});
