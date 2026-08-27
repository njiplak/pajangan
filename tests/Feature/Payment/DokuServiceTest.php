<?php

use App\Contract\Payment\PaymentStatus;
use App\Models\Order;
use App\Models\OrderItem;
use App\Service\Payment\DokuService;
use Illuminate\Support\Facades\Http;

function dokuTestOrder(): Order
{
    $order = Order::create([
        'order_number' => 'ORD-DOKU-001',
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

test('createTransaction sends the DOKU component signature over Client-Id/Request-Id/Request-Timestamp/Request-Target/Digest', function () {
    config(['services.doku.client_id' => 'MCH-0001', 'services.doku.secret_key' => 'doku-secret', 'services.doku.sandbox' => true]);

    Http::fake([
        'api-sandbox.doku.com/*' => Http::response([
            'payment' => ['url' => 'https://sandbox.doku.com/checkout/abc', 'token_id' => 'TOKEN-abc'],
            'order' => ['invoice_number' => 'ORD-DOKU-001'],
        ], 200),
    ]);

    $order = dokuTestOrder();
    $service = app(DokuService::class);

    $result = $service->createTransaction($order, 105000, []);

    expect($result['reference'])->toBe('ORD-DOKU-001');
    expect($result['redirect_url'])->toBe('https://sandbox.doku.com/checkout/abc');
    expect($result['token'])->toBe('TOKEN-abc');

    Http::assertSent(function ($request) {
        expect($request->url())->toContain('/checkout/v1/payment');
        expect($request->hasHeader('Client-Id', 'MCH-0001'))->toBeTrue();
        expect($request->hasHeader('Request-Id'))->toBeTrue();
        expect($request->hasHeader('Request-Timestamp'))->toBeTrue();
        expect($request->hasHeader('Signature'))->toBeTrue();

        $signatureHeader = $request->header('Signature')[0];
        expect($signatureHeader)->toStartWith('HMACSHA256=');

        // Recompute the expected signature from the exact bytes sent, the
        // same way the DOKU server would, and confirm they match.
        $rawBody = $request->body();
        $digest = base64_encode(hash('sha256', $rawBody, true));
        $component = 'Client-Id:'.$request->header('Client-Id')[0]."\n".
            'Request-Id:'.$request->header('Request-Id')[0]."\n".
            'Request-Timestamp:'.$request->header('Request-Timestamp')[0]."\n".
            "Request-Target:/checkout/v1/payment\n".
            "Digest:{$digest}";
        $expected = 'HMACSHA256='.base64_encode(hash_hmac('sha256', $component, 'doku-secret', true));

        expect($signatureHeader)->toBe($expected);

        $decodedBody = json_decode($rawBody, true);
        expect($decodedBody['order']['amount'])->toBe(105000);
        $itemsTotal = array_sum(array_map(fn ($i) => $i['price'] * $i['quantity'], $decodedBody['order']['line_items']));
        expect($itemsTotal)->toBe(105000);

        return true;
    });
});

test('verifyNotification rebuilds the signature over the configured notification path and rejects a tampered body', function () {
    config([
        'services.doku.client_id' => 'MCH-0001',
        'services.doku.secret_key' => 'doku-secret',
        'services.doku.notification_path' => '/payment/callback/doku',
    ]);

    $service = app(DokuService::class);

    $rawBody = json_encode(['order' => ['invoice_number' => 'ORD-DOKU-001', 'amount' => 105000], 'transaction' => ['status' => 'SUCCESS']]);

    $headers = [
        'client-id' => 'MCH-0001',
        'request-id' => 'req-123',
        'request-timestamp' => '2026-08-22T00:00:00Z',
    ];

    $digest = base64_encode(hash('sha256', $rawBody, true));
    $component = "Client-Id:MCH-0001\nRequest-Id:req-123\nRequest-Timestamp:2026-08-22T00:00:00Z\nRequest-Target:/payment/callback/doku\nDigest:{$digest}";
    $headers['signature'] = 'HMACSHA256='.base64_encode(hash_hmac('sha256', $component, 'doku-secret', true));

    expect($service->verifyNotification($rawBody, $headers))->toBeTrue();

    $headers['signature'] = 'HMACSHA256=tampered';
    expect($service->verifyNotification($rawBody, $headers))->toBeFalse();

    // A mismatched Client-Id must be rejected even if the signature math is right.
    $wrongClient = ['client-id' => 'MCH-9999'] + $headers;
    expect($service->verifyNotification($rawBody, $wrongClient))->toBeFalse();
});

test('parseNotification maps DOKU transaction statuses', function () {
    $service = app(DokuService::class);

    $paid = $service->parseNotification(['order' => ['invoice_number' => 'i', 'amount' => 1000], 'transaction' => ['status' => 'SUCCESS', 'date' => '2026-08-22T00:00:00Z']]);
    expect($paid['status'])->toBe(PaymentStatus::PAID);
    expect($paid['paid_at'])->toBe('2026-08-22T00:00:00Z');

    $expired = $service->parseNotification(['order' => ['invoice_number' => 'i', 'amount' => 1000], 'transaction' => ['status' => 'EXPIRED']]);
    expect($expired['status'])->toBe(PaymentStatus::EXPIRED);

    $timeout = $service->parseNotification(['order' => ['invoice_number' => 'i', 'amount' => 1000], 'transaction' => ['status' => 'TIMEOUT']]);
    expect($timeout['status'])->toBe(PaymentStatus::EXPIRED);
});
