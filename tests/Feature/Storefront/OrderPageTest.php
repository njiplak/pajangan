<?php

use App\Contract\Payment\PaymentStatus;
use App\Models\Order;
use Illuminate\Support\Facades\URL;

function orderPageOrder(array $overrides = []): Order
{
    $order = Order::create(array_merge([
        'order_number' => 'ORD-PAGE-001',
        'customer_name' => 'Yohana Wenda',
        'customer_email' => 'yohana@example.com',
        'customer_phone' => '0812',
        'shipping_address' => 'Jl. Sentani No. 9',
        'shipping_city' => 'Jayapura',
        'shipping_province' => 'Papua',
        'status' => Order::STATUS_PENDING,
        'subtotal' => 120000,
        'shipping_cost' => 15000,
        'admin_fee' => 4000,
        'total' => 139000,
        'courier_name' => 'JNE',
        'courier_service' => 'reg',
        'payment_payload' => ['secret_gateway_field' => 'do-not-leak'],
        'stock_draw' => ['1' => 2],
        'payment_reference' => 'INTERNAL-REF',
    ], $overrides));

    $order->items()->create([
        'product_id' => null,
        'product_name' => 'Kopi Wamena',
        'unit_price' => 60000,
        'quantity' => 2,
        'subtotal' => 120000,
    ]);

    return $order;
}

function visitOrderPage(Order $order)
{
    return test()->get(URL::signedRoute('order.show', ['order' => $order->order_number]));
}

test('the order page itemises what makes up the total', function () {
    $order = orderPageOrder();

    visitOrderPage($order)->assertInertia(fn ($page) => $page
        ->where('order.subtotal', 120000)
        ->where('order.shipping_cost', 15000)
        ->where('order.admin_fee', 4000)
        ->where('order.total', 139000)
        ->where('order.courier_name', 'JNE')
        ->where('order.items.0.product_name', 'Kopi Wamena'));
});

test('the order page never exposes gateway payloads or internal references', function () {
    $order = orderPageOrder();

    $props = visitOrderPage($order)->viewData('page')['props']['order'];

    expect($props)->not->toHaveKey('payment_payload');
    expect($props)->not->toHaveKey('stock_draw');
    expect($props)->not->toHaveKey('payment_reference');
    expect($props)->not->toHaveKey('biteship_order_id');
    expect(json_encode($props))->not->toContain('do-not-leak');
});

test('a shipped order shows its tracking number and how far it has come', function () {
    $order = orderPageOrder([
        'status' => Order::STATUS_SHIPPED,
        'payment_status' => PaymentStatus::PAID,
        'tracking_number' => 'JNE123456',
    ]);

    visitOrderPage($order)->assertInertia(fn ($page) => $page
        ->where('order.tracking_number', 'JNE123456')
        ->where('timeline.0.done', true)
        ->where('timeline.3.key', 'shipped')
        ->where('timeline.3.done', true)
        ->where('timeline.4.key', 'completed')
        ->where('timeline.4.done', false));
});

test('a pending order has only its first step done', function () {
    $order = orderPageOrder();

    visitOrderPage($order)->assertInertia(fn ($page) => $page
        ->where('order.status', 'pending')
        ->where('timeline.0.done', true)
        ->where('timeline.1.done', false));
});

test('a cancelled order reads as cancelled, not as a success', function () {
    $order = orderPageOrder(['status' => Order::STATUS_CANCELLED]);

    visitOrderPage($order)->assertInertia(fn ($page) => $page
        ->where('order.status', 'cancelled')
        ->has('timeline', 2)
        ->where('timeline.1.key', 'cancelled')
        ->where('payUrl', null));
});
