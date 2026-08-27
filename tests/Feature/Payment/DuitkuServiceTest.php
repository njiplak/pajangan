<?php

use App\Contract\Payment\PaymentStatus;
use App\Models\Order;
use App\Models\OrderItem;
use App\Service\Payment\DuitkuService;
use Illuminate\Support\Facades\Http;

function duitkuTestOrder(): Order
{
    $order = Order::create([
        'order_number' => 'ORD-DUITKU-001',
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

test('createTransaction signs with md5(merchantCode+merchantOrderId+amount+apiKey) and normalizes reference to the order number', function () {
    config([
        'services.duitku.merchant_code' => 'D1234',
        'services.duitku.api_key' => 'duitku-api-key',
        'services.duitku.sandbox' => true,
    ]);

    Http::fake([
        'sandbox.duitku.com/*' => Http::response([
            'reference' => 'DUITKU-REF-999',
            'paymentUrl' => 'https://sandbox.duitku.com/topup/xyz',
            'statusCode' => '00',
            'statusMessage' => 'SUCCESS',
        ], 200),
    ]);

    $order = duitkuTestOrder();
    $service = app(DuitkuService::class);

    $result = $service->createTransaction($order, 105000, ['method' => 'BC']);

    // Unlike other gateways, Duitku's own status-check API is keyed by
    // merchantOrderId, so `reference` is normalized to the order number.
    expect($result['reference'])->toBe('ORD-DUITKU-001');
    expect($result['redirect_url'])->toBe('https://sandbox.duitku.com/topup/xyz');
    expect($result['raw']['reference'])->toBe('DUITKU-REF-999');

    Http::assertSent(function ($request) {
        $expectedSignature = md5('D1234'.'ORD-DUITKU-001'.'105000'.'duitku-api-key');

        expect($request->url())->toContain('/webapi/api/merchant/v2/inquiry');
        expect($request['signature'])->toBe($expectedSignature);
        expect($request['paymentMethod'])->toBe('BC');

        return true;
    });
});

test('createTransaction requires a payment method', function () {
    $service = app(DuitkuService::class);

    $service->createTransaction(duitkuTestOrder(), 100000, []);
})->throws(InvalidArgumentException::class);

test('createTransaction throws when Duitku returns a non-success statusCode', function () {
    config(['services.duitku.merchant_code' => 'D1234', 'services.duitku.api_key' => 'key']);

    Http::fake([
        'sandbox.duitku.com/*' => Http::response(['statusCode' => '01', 'statusMessage' => 'Invalid signature'], 200),
    ]);

    app(DuitkuService::class)->createTransaction(duitkuTestOrder(), 100000, ['method' => 'BC']);
})->throws(RuntimeException::class);

test('listChannels signs with sha256(merchantCode+amount+datetime+apiKey) and maps paymentFee entries', function () {
    config(['services.duitku.merchant_code' => 'D1234', 'services.duitku.api_key' => 'duitku-api-key', 'services.duitku.sandbox' => true]);

    Http::fake([
        'sandbox.duitku.com/*' => Http::response([
            'paymentFee' => [
                ['paymentMethod' => 'VA', 'paymentName' => 'MAYBANK VA', 'paymentImage' => 'https://x/va.png', 'totalFee' => '4000'],
                ['paymentMethod' => 'BT', 'paymentName' => 'PERMATA VA', 'paymentImage' => 'https://x/bt.png', 'totalFee' => '4000'],
            ],
        ], 200),
    ]);

    $channels = app(DuitkuService::class)->listChannels();

    expect($channels)->toBe([
        ['code' => 'VA', 'name' => 'MAYBANK VA', 'fee_flat' => 4000, 'fee_percent' => null],
        ['code' => 'BT', 'name' => 'PERMATA VA', 'fee_flat' => 4000, 'fee_percent' => null],
    ]);

    Http::assertSent(function ($request) {
        expect($request->url())->toContain('/webapi/api/merchant/paymentmethod/getpaymentmethod');
        expect($request['merchantCode'])->toBe('D1234');

        $expectedSignature = hash('sha256', 'D1234'.$request['amount'].$request['datetime'].'duitku-api-key');
        expect($request['signature'])->toBe($expectedSignature);

        return true;
    });
});

test('verifyNotification parses the x-www-form-urlencoded callback body and validates md5 signature', function () {
    config(['services.duitku.api_key' => 'duitku-api-key']);

    $service = app(DuitkuService::class);

    $fields = ['merchantCode' => 'D1234', 'amount' => '105000', 'merchantOrderId' => 'ORD-DUITKU-001'];
    $fields['signature'] = md5($fields['merchantCode'].$fields['amount'].$fields['merchantOrderId'].'duitku-api-key');
    $rawBody = http_build_query($fields);

    expect($service->verifyNotification($rawBody))->toBeTrue();

    $fields['signature'] = 'tampered';
    expect($service->verifyNotification(http_build_query($fields)))->toBeFalse();
});

test('parseNotification maps resultCode to normalized status', function () {
    $service = app(DuitkuService::class);

    expect($service->parseNotification(['merchantOrderId' => 'o', 'resultCode' => '00', 'amount' => '1000'])['status'])->toBe(PaymentStatus::PAID);
    expect($service->parseNotification(['merchantOrderId' => 'o', 'resultCode' => '01', 'amount' => '1000'])['status'])->toBe(PaymentStatus::PENDING);
    expect($service->parseNotification(['merchantOrderId' => 'o', 'resultCode' => '02', 'amount' => '1000'])['status'])->toBe(PaymentStatus::FAILED);
});
