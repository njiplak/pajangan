<?php

use App\Models\Order;

function callbackTestOrder(string $paymentStatus = 'pending'): Order
{
    return Order::create([
        'order_number' => 'ORD-CB-001',
        'customer_name' => 'Budi',
        'customer_email' => 'budi@example.com',
        'customer_phone' => '08123456789',
        'shipping_address' => 'Jl. Test',
        'shipping_city' => 'Jayapura',
        'shipping_province' => 'Papua',
        'status' => Order::STATUS_PENDING,
        'subtotal' => 100000,
        'total' => 100000,
        'payment_gateway' => 'tripay',
        'payment_reference' => 'DEV-cb-ref',
        'payment_status' => $paymentStatus,
    ]);
}

function postTripayCallback(array $payload, ?string $signature = null): \Illuminate\Testing\TestResponse
{
    config(['services.tripay.private_key' => 'private-key']);

    $rawBody = json_encode($payload);
    $signature ??= hash_hmac('sha256', $rawBody, 'private-key');

    return test()->call(
        'POST',
        route('payment.callback', ['gateway' => 'tripay']),
        [],
        [],
        [],
        ['HTTP_X-Callback-Signature' => $signature, 'CONTENT_TYPE' => 'application/json'],
        $rawBody
    );
}

test('a validly signed PAID callback marks the order paid and sets paid_at', function () {
    $order = callbackTestOrder();

    $response = postTripayCallback([
        'reference' => 'DEV-cb-ref',
        'merchant_ref' => 'ORD-CB-001',
        'status' => 'PAID',
        'total_amount' => 100000,
        'paid_at' => 1735689600,
    ]);

    $response->assertOk();

    $order->refresh();
    expect($order->payment_status)->toBe('paid');
    expect($order->status)->toBe(Order::STATUS_PAID);
    expect($order->paid_at)->not->toBeNull();
});

test('a callback with an invalid signature is rejected and does not mutate the order', function () {
    $order = callbackTestOrder();

    $response = postTripayCallback([
        'reference' => 'DEV-cb-ref',
        'merchant_ref' => 'ORD-CB-001',
        'status' => 'PAID',
        'total_amount' => 100000,
    ], signature: 'not-the-real-signature');

    $response->assertForbidden();

    $order->refresh();
    expect($order->payment_status)->toBe('pending');
    expect($order->status)->toBe(Order::STATUS_PENDING);
});

test('a duplicate delivery of the same status is a no-op', function () {
    $order = callbackTestOrder(paymentStatus: 'paid');
    $order->update(['status' => Order::STATUS_PAID, 'paid_at' => now()]);
    $originalPaidAt = $order->paid_at;

    postTripayCallback([
        'reference' => 'DEV-cb-ref',
        'merchant_ref' => 'ORD-CB-001',
        'status' => 'PAID',
        'total_amount' => 100000,
    ])->assertOk();

    $order->refresh();
    expect($order->paid_at->equalTo($originalPaidAt))->toBeTrue();
});

test('a stale EXPIRED callback cannot downgrade an order that is already paid', function () {
    $order = callbackTestOrder(paymentStatus: 'paid');
    $order->update(['status' => Order::STATUS_PAID, 'paid_at' => now()]);

    postTripayCallback([
        'reference' => 'DEV-cb-ref',
        'merchant_ref' => 'ORD-CB-001',
        'status' => 'EXPIRED',
        'total_amount' => 100000,
    ])->assertOk();

    $order->refresh();
    expect($order->payment_status)->toBe('paid');
    expect($order->status)->toBe(Order::STATUS_PAID);
});

test('a callback for an unregistered gateway 404s', function () {
    $response = test()->postJson(route('payment.callback', ['gateway' => 'not-a-real-gateway']), []);

    $response->assertNotFound();
});

test('a callback referencing an order that does not exist is accepted but changes nothing', function () {
    $response = postTripayCallback([
        'reference' => 'DEV-does-not-exist',
        'merchant_ref' => 'ORD-GHOST',
        'status' => 'PAID',
        'total_amount' => 100000,
    ]);

    $response->assertOk();
    expect(Order::count())->toBe(0);
});

test('a form-urlencoded Duitku-style callback is parsed and processed through the real route', function () {
    config(['services.duitku.api_key' => 'duitku-api-key']);

    $order = Order::create([
        'order_number' => 'ORD-CB-DUITKU',
        'customer_name' => 'Budi',
        'customer_email' => 'budi@example.com',
        'customer_phone' => '08123456789',
        'shipping_address' => 'Jl. Test',
        'shipping_city' => 'Jayapura',
        'shipping_province' => 'Papua',
        'status' => Order::STATUS_PENDING,
        'subtotal' => 100000,
        'total' => 100000,
        'payment_gateway' => 'duitku',
        'payment_reference' => 'ORD-CB-DUITKU',
        'payment_status' => 'pending',
    ]);

    $fields = [
        'merchantCode' => 'D1234',
        'amount' => '100000',
        'merchantOrderId' => 'ORD-CB-DUITKU',
        'resultCode' => '00',
        'reference' => 'DUITKU-REF-1',
    ];
    $fields['signature'] = md5($fields['merchantCode'].$fields['amount'].$fields['merchantOrderId'].'duitku-api-key');

    // Symfony's test Request::create() doesn't replicate PHP's real
    // SAPI-level $_POST parsing from a raw body the way a live server
    // does, so both the parsed fields (for $request->all(), simulating
    // $_POST) and the matching raw content (for getContent(), simulating
    // php://input) must be supplied for this to faithfully simulate a
    // real form-encoded webhook delivery.
    $response = test()->call(
        'POST',
        route('payment.callback', ['gateway' => 'duitku']),
        $fields,
        [],
        [],
        ['CONTENT_TYPE' => 'application/x-www-form-urlencoded'],
        http_build_query($fields)
    );

    $response->assertOk();

    $order->refresh();
    expect($order->payment_status)->toBe('paid');
    expect($order->status)->toBe(Order::STATUS_PAID);
});
