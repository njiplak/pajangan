<?php

use App\Contract\Payment\PaymentStatus;
use App\Models\Order;
use App\Models\OrderActivity;
use App\Models\Product;
use App\Models\Setting;
use App\Models\User;
use App\Service\Order\ManualOrderService;
use Illuminate\Support\Facades\Http;
use Spatie\Permission\Models\Permission;

function rulesStaff(): User
{
    $user = User::factory()->create();

    foreach (['order.view', 'order.update'] as $permission) {
        Permission::firstOrCreate(['name' => $permission, 'guard_name' => 'web']);
        $user->givePermissionTo($permission);
    }

    return $user;
}

function rulesOrder(array $overrides = []): Order
{
    return Order::create(array_merge([
        'order_number' => 'ORD-RL-001',
        'customer_name' => 'Yohana Wenda',
        'customer_email' => 'yohana@example.com',
        'customer_phone' => '0812',
        'shipping_address' => 'Jl. Test',
        'shipping_city' => 'Jayapura',
        'shipping_province' => 'Papua',
        'status' => Order::STATUS_PAID,
        'subtotal' => 120000,
        'total' => 135000,
    ], $overrides));
}

function rulesBiteship(): void
{
    config([
        'services.biteship.api_key' => 'biteship_test.abc',
        'services.biteship.base_url' => 'https://api.biteship.com',
        'services.biteship.origin_area_id' => 'ORIGIN-1',
        'services.biteship.sender_name' => 'Toko',
        'services.biteship.sender_phone' => '0812000',
        'services.biteship.sender_address' => 'Jl. Gudang 1',
    ]);

    Http::fake([
        'api.biteship.com/v1/orders' => Http::response([
            'success' => true, 'id' => 'bts-rl-1', 'status' => 'confirmed', 'price' => 15000,
            'courier' => ['company' => 'jne', 'type' => 'reg', 'waybill_id' => 'JNE1'],
        ], 200),
        'api.biteship.com/v1/orders/bts-rl-1/cancel' => Http::response([
            'success' => true, 'id' => 'bts-rl-1', 'status' => 'cancelled',
        ], 200),
    ]);
}

function rulesCreateShipment(User $staff, Order $order): \Illuminate\Testing\TestResponse
{
    return test()->actingAs($staff)->post(route('backoffice.order.shipping-create', $order->id), [
        'destination_area_id' => 'AREA-1',
        'weight_gram' => 500,
        'courier_code' => 'jne',
        'courier_service_code' => 'reg',
    ]);
}

// --- A shipment is only created for an order that is paid and not yet sent --

test('a shipment can be created for a paid or processing order', function (string $status) {
    rulesBiteship();
    $order = rulesOrder(['status' => $status]);

    rulesCreateShipment(rulesStaff(), $order)->assertSessionHasNoErrors();

    expect($order->fresh()->biteship_order_id)->toBe('bts-rl-1');
})->with([Order::STATUS_PAID, Order::STATUS_PROCESSING]);

test('no courier is booked for an order that is unpaid, already sent, finished or cancelled', function (string $status) {
    rulesBiteship();
    $order = rulesOrder(['status' => $status]);

    rulesCreateShipment(rulesStaff(), $order)->assertSessionHasErrors('errors');

    expect($order->fresh()->biteship_order_id)->toBeNull();
    Http::assertNothingSent();
})->with([Order::STATUS_PENDING, Order::STATUS_SHIPPED, Order::STATUS_COMPLETED, Order::STATUS_CANCELLED]);

// --- Cancelling a shipment takes the order back to "being prepared" ---------

test('cancelling the shipment of a shipped order moves it back to processing and says so in the log', function () {
    rulesBiteship();
    $staff = rulesStaff();
    $order = rulesOrder([
        'status' => Order::STATUS_SHIPPED, 'biteship_order_id' => 'bts-rl-1',
        'tracking_number' => 'JNE1', 'shipment_status' => 'picked',
    ]);

    $this->actingAs($staff)->post(route('backoffice.order.shipping-cancel', $order->id), [
        'cancellation_reason_code' => 'change_courier',
    ])->assertSessionHasNoErrors();

    $order->refresh();
    expect($order->status)->toBe(Order::STATUS_PROCESSING);
    expect($order->shipment_status)->toBeNull();
    expect(OrderActivity::where('action', 'shipment_cancelled')->sole()->description)->toContain('Diproses');
});

test('cancelling the shipment of an order not yet shipped leaves its status alone', function () {
    rulesBiteship();
    $order = rulesOrder(['status' => Order::STATUS_PAID, 'biteship_order_id' => 'bts-rl-1']);

    $this->actingAs(rulesStaff())->post(route('backoffice.order.shipping-cancel', $order->id), [
        'cancellation_reason_code' => 'change_courier',
    ])->assertSessionHasNoErrors();

    expect($order->fresh()->status)->toBe(Order::STATUS_PAID);
});

test('after cancelling, a new shipment can be booked for the same order', function () {
    rulesBiteship();
    $staff = rulesStaff();
    $order = rulesOrder(['status' => Order::STATUS_SHIPPED, 'biteship_order_id' => 'bts-rl-1']);

    $this->actingAs($staff)->post(route('backoffice.order.shipping-cancel', $order->id), [
        'cancellation_reason_code' => 'change_courier',
    ])->assertSessionHasNoErrors();

    rulesCreateShipment($staff, $order)->assertSessionHasNoErrors();

    expect($order->fresh()->biteship_order_id)->toBe('bts-rl-1');
});

// --- Chat orders get their own, longer, payment window ----------------------

function rulesManualOrder(int $ageHours): Order
{
    $product = Product::create(['name' => 'Noken', 'price' => 50000, 'stock' => 10, 'weight_gram' => 300, 'is_active' => true]);

    $order = app(ManualOrderService::class)->create([
        'customer_name' => 'Mama Yosina', 'customer_email' => null, 'customer_phone' => '0812',
        'shipping_address' => 'Jl. Pasar', 'shipping_city' => 'Jayapura', 'shipping_province' => 'Papua',
        'items' => [['product_id' => $product->id, 'quantity' => 2]],
        'shipping_cost' => 0, 'paid' => false,
    ], null);

    Order::query()->whereKey($order->id)->update(['created_at' => now()->subHours($ageHours)]);

    return $order;
}

test('a manual order is marked as one', function () {
    expect(rulesManualOrder(0)->source)->toBe(Order::SOURCE_MANUAL);
});

test('an unpaid manual order outlives the online window', function () {
    $order = rulesManualOrder(30);

    $this->artisan('orders:expire-unpaid')->assertSuccessful();

    expect($order->fresh()->status)->toBe(Order::STATUS_PENDING);
    expect(Product::first()->stock)->toBe(8);
});

test('an unpaid manual order past its own window is cancelled and restocked', function () {
    $order = rulesManualOrder(73);

    $this->artisan('orders:expire-unpaid')->assertSuccessful();

    expect($order->fresh()->status)->toBe(Order::STATUS_CANCELLED);
    expect(Product::first()->stock)->toBe(10);
    expect(OrderActivity::where('action', 'status')->sole()->description)->toContain('72 jam');
});

test('the manual window is read from settings, and 0 turns auto-cancel off for manual orders', function () {
    Setting::updateOrCreate(['key' => 'manual_order_unpaid_hold_hours'], ['value' => '0']);
    $order = rulesManualOrder(500);

    $this->artisan('orders:expire-unpaid')->assertSuccessful();

    expect($order->fresh()->status)->toBe(Order::STATUS_PENDING);
});

test('a manual order keeps its window after the customer opens an online payment for it', function () {
    $order = rulesManualOrder(30);
    // What OrderLookupController::pay does via PaymentService::initiate.
    $order->update(['payment_gateway' => 'test-default-gateway', 'payment_reference' => 'TEST-1', 'payment_status' => PaymentStatus::PENDING]);
    fakeDefaultPaymentGateway();

    $this->artisan('orders:expire-unpaid')->assertSuccessful();

    expect($order->fresh()->status)->toBe(Order::STATUS_PENDING);
});

test('online orders still expire on the online window', function () {
    $order = rulesOrder(['status' => Order::STATUS_PENDING, 'stock_draw' => []]);
    Order::query()->whereKey($order->id)->update(['created_at' => now()->subHours(25)]);

    $this->artisan('orders:expire-unpaid')->assertSuccessful();

    expect($order->fresh()->status)->toBe(Order::STATUS_CANCELLED);
    expect($order->fresh()->source)->toBe(Order::SOURCE_STOREFRONT);
});

test('the manual order form states how long an unpaid order is held', function () {
    $this->actingAs(rulesStaff())->get(route('backoffice.order.create'))
        ->assertInertia(fn ($page) => $page->where('manualHoldHours', 72));
});
