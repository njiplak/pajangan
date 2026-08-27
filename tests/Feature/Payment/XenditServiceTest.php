<?php

use App\Contract\Payment\PaymentStatus;
use App\Models\Order;
use App\Models\OrderItem;
use App\Service\Payment\XenditService;
use Illuminate\Support\Facades\Http;

function xenditTestOrder(): Order
{
    $order = Order::create([
        'order_number' => 'ORD-XENDIT-001',
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

test('createTransaction posts to the invoice endpoint with Basic Auth and a fees entry for the admin fee', function () {
    config(['services.xendit.secret_key' => 'xnd_development_abc']);

    Http::fake([
        'api.xendit.co/*' => Http::response([
            'id' => '65b3f...',
            'invoice_url' => 'https://checkout.xendit.co/web/65b3f',
            'expiry_date' => '2026-08-23T00:00:00.000Z',
        ], 200),
    ]);

    $order = xenditTestOrder();
    $service = app(XenditService::class);

    $result = $service->createTransaction($order, 105000, []);

    expect($result['reference'])->toBe('65b3f...');
    expect($result['redirect_url'])->toBe('https://checkout.xendit.co/web/65b3f');
    expect($result['expires_at'])->toBe('2026-08-23T00:00:00.000Z');

    Http::assertSent(function ($request) {
        expect($request->url())->toContain('/v2/invoices');
        expect($request->hasHeader('Authorization', 'Basic '.base64_encode('xnd_development_abc:')))->toBeTrue();
        expect($request['external_id'])->toBe('ORD-XENDIT-001');
        expect($request['amount'])->toBe(105000);
        expect($request['fees'][0]['value'])->toBe(5000);

        return true;
    });
});

test('verifyNotification does a constant-time comparison of the callback token, not the body', function () {
    config(['services.xendit.callback_token' => 'my-verification-token']);

    $service = app(XenditService::class);

    expect($service->verifyNotification('{"id":"1"}', ['x-callback-token' => 'my-verification-token']))->toBeTrue();
    expect($service->verifyNotification('{"id":"1"}', ['x-callback-token' => 'wrong-token']))->toBeFalse();
    expect($service->verifyNotification('{"id":"1"}', []))->toBeFalse();
});

test('parseNotification maps invoice statuses', function () {
    $service = app(XenditService::class);

    expect($service->parseNotification(['id' => 'x', 'status' => 'PAID', 'paid_amount' => 105000])['status'])->toBe(PaymentStatus::PAID);
    expect($service->parseNotification(['id' => 'x', 'status' => 'SETTLED', 'paid_amount' => 105000])['status'])->toBe(PaymentStatus::PAID);
    expect($service->parseNotification(['id' => 'x', 'status' => 'EXPIRED', 'amount' => 105000])['status'])->toBe(PaymentStatus::EXPIRED);
});
