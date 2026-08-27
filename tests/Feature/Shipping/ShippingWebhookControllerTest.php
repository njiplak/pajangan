<?php

use App\Models\Order;
use Illuminate\Support\Facades\Http;

function shippingWebhookOrder(): Order
{
    return Order::create([
        'order_number' => 'ORD-WH-001',
        'customer_name' => 'Budi',
        'customer_email' => 'budi@example.com',
        'customer_phone' => '08123456789',
        'shipping_address' => 'Jl. Test',
        'shipping_city' => 'Jayapura',
        'shipping_province' => 'Papua',
        'status' => Order::STATUS_PENDING,
        'subtotal' => 150000,
        'total' => 150000,
        'biteship_order_id' => 'bts-order-1',
        'courier_code' => 'jne',
        'courier_service' => 'reg',
    ]);
}

function biteshipWebhookConfig(): void
{
    config([
        'services.biteship.api_key' => 'biteship_test.abc',
        'services.biteship.base_url' => 'https://api.biteship.com',
        'services.biteship.origin_area_id' => 'IDNP6IDNC148IDND854IDZ10730',
        'services.biteship.webhook_token' => 'super-secret-token',
    ]);
}

test('a wrong webhook token is rejected without touching the order or calling Biteship', function () {
    biteshipWebhookConfig();
    $order = shippingWebhookOrder();

    Http::fake();

    $this->postJson(route('webhook.shipping.biteship', ['token' => 'wrong-token']), [
        'order_id' => 'bts-order-1',
    ])->assertOk();

    Http::assertNothingSent();
    expect($order->fresh()->tracking_number)->toBeNull();
});

test('an unconfigured webhook token rejects every request', function () {
    config(['services.biteship.webhook_token' => null]);
    shippingWebhookOrder();

    Http::fake();

    $this->postJson(route('webhook.shipping.biteship', ['token' => 'anything']), [
        'order_id' => 'bts-order-1',
    ])->assertOk();

    Http::assertNothingSent();
});

test('a valid token re-fetches the order from Biteship and writes only what the API confirms, not the payload', function () {
    biteshipWebhookConfig();
    $order = shippingWebhookOrder();

    Http::fake([
        'api.biteship.com/v1/orders/bts-order-1' => Http::response([
            'success' => true,
            'id' => 'bts-order-1',
            'status' => 'allocated',
            'price' => 27000,
            'courier' => ['company' => 'jne', 'type' => 'reg', 'waybill_id' => 'WYB-999'],
        ], 200),
    ]);

    $this->postJson(route('webhook.shipping.biteship', ['token' => 'super-secret-token']), [
        'order_id' => 'bts-order-1',
        // A forged payload claiming a different waybill must be ignored —
        // only the re-fetched API response is trusted.
        'courier_waybill_id' => 'FORGED-000',
    ])->assertOk();

    $order->refresh();
    expect($order->tracking_number)->toBe('WYB-999');
    expect($order->shipping_cost)->toBe(27000);
    // "allocated" is a courier-engaged-but-not-shipped status — advances
    // fulfillment from pending to processing.
    expect($order->status)->toBe(Order::STATUS_PROCESSING);
});

test('a webhook for an unknown biteship order id is a no-op, not an error', function () {
    biteshipWebhookConfig();

    Http::fake();

    $this->postJson(route('webhook.shipping.biteship', ['token' => 'super-secret-token']), [
        'order_id' => 'does-not-exist',
    ])->assertOk();

    Http::assertNothingSent();
});

test('status matching is case and separator insensitive', function () {
    biteshipWebhookConfig();
    $order = shippingWebhookOrder();

    Http::fake([
        'api.biteship.com/v1/orders/bts-order-1' => Http::response([
            'success' => true,
            'id' => 'bts-order-1',
            'status' => 'droppingOff',
            'courier' => ['company' => 'jne', 'type' => 'reg', 'waybill_id' => 'WYB-1'],
        ], 200),
    ]);

    $this->postJson(route('webhook.shipping.biteship', ['token' => 'super-secret-token']), [
        'order_id' => 'bts-order-1',
    ])->assertOk();

    expect($order->fresh()->status)->toBe(Order::STATUS_SHIPPED);
});

test('a stale webhook cannot move fulfillment status backward', function () {
    biteshipWebhookConfig();
    $order = shippingWebhookOrder();
    $order->update(['status' => Order::STATUS_COMPLETED]);

    Http::fake([
        'api.biteship.com/v1/orders/bts-order-1' => Http::response([
            'success' => true,
            'id' => 'bts-order-1',
            'status' => 'confirmed',
            'courier' => ['company' => 'jne', 'type' => 'reg', 'waybill_id' => null],
        ], 200),
    ]);

    $this->postJson(route('webhook.shipping.biteship', ['token' => 'super-secret-token']), [
        'order_id' => 'bts-order-1',
    ])->assertOk();

    expect($order->fresh()->status)->toBe(Order::STATUS_COMPLETED);
});

test('a "problem" status like cancelled does not auto-change fulfillment status', function () {
    biteshipWebhookConfig();
    $order = shippingWebhookOrder();
    $order->update(['status' => Order::STATUS_PROCESSING]);

    Http::fake([
        'api.biteship.com/v1/orders/bts-order-1' => Http::response([
            'success' => true,
            'id' => 'bts-order-1',
            'status' => 'cancelled',
            'courier' => ['company' => 'jne', 'type' => 'reg', 'waybill_id' => null],
        ], 200),
    ]);

    $this->postJson(route('webhook.shipping.biteship', ['token' => 'super-secret-token']), [
        'order_id' => 'bts-order-1',
    ])->assertOk();

    // Status is left for staff to review — not silently flipped to
    // 'cancelled' or left implying everything is still fine.
    expect($order->fresh()->status)->toBe(Order::STATUS_PROCESSING);
});

test('fulfillment status never regresses once cancelled manually by staff', function () {
    biteshipWebhookConfig();
    $order = shippingWebhookOrder();
    $order->update(['status' => Order::STATUS_CANCELLED]);

    Http::fake([
        'api.biteship.com/v1/orders/bts-order-1' => Http::response([
            'success' => true,
            'id' => 'bts-order-1',
            'status' => 'delivered',
            'courier' => ['company' => 'jne', 'type' => 'reg', 'waybill_id' => 'WYB-1'],
        ], 200),
    ]);

    $this->postJson(route('webhook.shipping.biteship', ['token' => 'super-secret-token']), [
        'order_id' => 'bts-order-1',
    ])->assertOk();

    expect($order->fresh()->status)->toBe(Order::STATUS_CANCELLED);
});
