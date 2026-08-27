<?php

use App\Models\Order;
use App\Models\User;
use Illuminate\Support\Facades\Http;
use Spatie\Permission\Models\Permission;

function orderShippingAdmin(array $permissions): User
{
    $user = User::factory()->create();

    foreach ($permissions as $permission) {
        Permission::firstOrCreate(['name' => $permission, 'guard_name' => 'web']);
        $user->givePermissionTo($permission);
    }

    return $user;
}

function shippingTestOrder(): Order
{
    return Order::create([
        'order_number' => 'ORD-SHIP-001',
        'customer_name' => 'Budi',
        'customer_email' => 'budi@example.com',
        'customer_phone' => '08123456789',
        'shipping_address' => 'Jl. Test',
        'shipping_city' => 'Jayapura',
        'shipping_province' => 'Papua',
        'status' => Order::STATUS_PENDING,
        'subtotal' => 150000,
        'total' => 150000,
    ]);
}

function fakeBiteshipConfig(): void
{
    config([
        'services.biteship.api_key' => 'biteship_test.abc',
        'services.biteship.base_url' => 'https://api.biteship.com',
        'services.biteship.origin_area_id' => 'IDNP6IDNC148IDND854IDZ10730',
        'services.biteship.couriers' => 'jne,jnt',
    ]);
}

function fakeBiteshipSenderConfig(): void
{
    fakeBiteshipConfig();
    config([
        'services.biteship.sender_name' => 'Toko Pajangan',
        'services.biteship.sender_phone' => '081234567890',
        'services.biteship.sender_address' => 'Jl. Gudang No. 1',
    ]);
}

test('guests are redirected away from the shipping area search', function () {
    $this->get(route('backoffice.order.shipping-areas', ['q' => 'Jayapura']))
        ->assertRedirect(route('login'));
});

test('a user without order.update is forbidden from searching areas', function () {
    $user = orderShippingAdmin(['order.view']);

    $this->actingAs($user)
        ->get(route('backoffice.order.shipping-areas', ['q' => 'Jayapura']))
        ->assertForbidden();
});

test('area search returns an empty list for a short query without calling the API', function () {
    $user = orderShippingAdmin(['order.update']);
    fakeBiteshipConfig();

    Http::fake();

    $response = $this->actingAs($user)
        ->get(route('backoffice.order.shipping-areas', ['q' => 'Ja']));

    $response->assertOk();
    $response->assertJson(['areas' => []]);
    Http::assertNothingSent();
});

test('area search proxies to Biteship and returns the mapped areas', function () {
    $user = orderShippingAdmin(['order.update']);
    fakeBiteshipConfig();

    Http::fake([
        'api.biteship.com/v1/maps/areas*' => Http::response([
            'success' => true,
            'areas' => [
                ['id' => 'IDNP6IDNC148IDND854IDZ10730', 'name' => 'Jayapura, Papua', 'postal_code' => '99111'],
            ],
        ], 200),
    ]);

    $response = $this->actingAs($user)
        ->get(route('backoffice.order.shipping-areas', ['q' => 'Jayapura']));

    $response->assertOk();
    $response->assertJson(['areas' => [
        ['id' => 'IDNP6IDNC148IDND854IDZ10730', 'name' => 'Jayapura, Papua', 'postal_code' => '99111'],
    ]]);
});

test('quoting rates uses the order subtotal as the declared item value', function () {
    $user = orderShippingAdmin(['order.update']);
    fakeBiteshipConfig();
    $order = shippingTestOrder();

    Http::fake([
        'api.biteship.com/v1/rates/couriers' => Http::response([
            'success' => true,
            'pricing' => [[
                'courier_code' => 'jne',
                'courier_name' => 'JNE',
                'courier_service_code' => 'reg',
                'courier_service_name' => 'Regular',
                'price' => 25000,
                'duration' => '2-3 days',
            ]],
        ], 200),
    ]);

    $response = $this->actingAs($user)->postJson(
        route('backoffice.order.shipping-rates', ['id' => $order->id]),
        ['destination_area_id' => 'IDNP1IDNC1IDND1IDZ10110', 'weight_gram' => 1500]
    );

    $response->assertOk();
    $response->assertJson(['rates' => [[
        'courier_code' => 'jne',
        'courier_name' => 'JNE',
        'courier_service_code' => 'reg',
        'courier_service_name' => 'Regular',
        'price' => 25000,
        'duration' => '2-3 days',
    ]]]);

    Http::assertSent(function ($request) use ($order) {
        expect($request['items'][0]['value'])->toBe($order->subtotal);

        return true;
    });
});

test('quoting rates 404s for an order that does not exist', function () {
    $user = orderShippingAdmin(['order.update']);
    fakeBiteshipConfig();

    $response = $this->actingAs($user)->postJson(
        route('backoffice.order.shipping-rates', ['id' => 999999]),
        ['destination_area_id' => 'x', 'weight_gram' => 1000]
    );

    $response->assertNotFound();
});

test('saving shipping info records the chosen courier without touching the order total', function () {
    $user = orderShippingAdmin(['order.update']);
    $order = shippingTestOrder();

    $response = $this->actingAs($user)->put(
        route('backoffice.order.update-shipping', ['id' => $order->id]),
        [
            'shipping_cost' => 25000,
            'shipping_area_id' => 'IDNP1IDNC1IDND1IDZ10110',
            'shipping_area_name' => 'Jakarta Selatan',
            'courier_code' => 'jne',
            'courier_name' => 'JNE',
            'courier_service' => 'Regular',
            'courier_etd' => '2-3 days',
            'tracking_number' => 'JNE123456789',
        ]
    );

    $response->assertRedirect();

    $order->refresh();
    expect($order->shipping_cost)->toBe(25000);
    expect($order->courier_code)->toBe('jne');
    expect($order->courier_service)->toBe('Regular');
    expect($order->tracking_number)->toBe('JNE123456789');
    expect($order->total)->toBe(150000);
});

test('saving shipping info requires courier_code and courier_service', function () {
    $user = orderShippingAdmin(['order.update']);
    $order = shippingTestOrder();

    $response = $this->actingAs($user)->put(
        route('backoffice.order.update-shipping', ['id' => $order->id]),
        ['shipping_cost' => 25000]
    );

    $response->assertSessionHasErrors(['courier_code', 'courier_service']);
});

test('creating a shipment calls Biteship with the order customer as destination and persists the result', function () {
    $user = orderShippingAdmin(['order.update']);
    fakeBiteshipSenderConfig();
    $order = shippingTestOrder();

    Http::fake([
        'api.biteship.com/v1/orders' => Http::response([
            'success' => true,
            'id' => 'bts-order-1',
            'status' => 'confirmed',
            'price' => 25000,
            'courier' => ['company' => 'jne', 'type' => 'reg', 'waybill_id' => null],
        ], 200),
    ]);

    $response = $this->actingAs($user)->post(
        route('backoffice.order.shipping-create', ['id' => $order->id]),
        [
            'destination_area_id' => 'IDNP1IDNC1IDND1IDZ10110',
            'destination_area_name' => 'Jakarta Selatan',
            'weight_gram' => 1500,
            'courier_code' => 'jne',
            'courier_service_code' => 'reg',
        ]
    );

    $response->assertRedirect();
    $response->assertSessionDoesntHaveErrors();

    $order->refresh();
    expect($order->biteship_order_id)->toBe('bts-order-1');
    expect($order->courier_code)->toBe('jne');
    expect($order->shipping_cost)->toBe(25000);
    expect($order->shipping_area_id)->toBe('IDNP1IDNC1IDND1IDZ10110');
    expect($order->shipping_area_name)->toBe('Jakarta Selatan');
    expect($order->tracking_number)->toBeNull();
    expect($order->total)->toBe(150000);

    Http::assertSent(function ($request) use ($order) {
        expect($request['destination_contact_name'])->toBe($order->customer_name);
        expect($request['destination_contact_phone'])->toBe($order->customer_phone);
        expect($request['destination_address'])->toBe($order->shipping_address);
        expect($request['items'][0]['value'])->toBe($order->subtotal);

        return true;
    });
});

test('creating a shipment is refused when one already exists for the order', function () {
    $user = orderShippingAdmin(['order.update']);
    fakeBiteshipSenderConfig();
    $order = shippingTestOrder();
    $order->update(['biteship_order_id' => 'bts-existing']);

    Http::fake();

    $response = $this->actingAs($user)->post(
        route('backoffice.order.shipping-create', ['id' => $order->id]),
        [
            'destination_area_id' => 'x',
            'weight_gram' => 1000,
            'courier_code' => 'jne',
            'courier_service_code' => 'reg',
        ]
    );

    $response->assertSessionHasErrors('errors');
    Http::assertNothingSent();
});

test('creating a shipment surfaces the Biteship error instead of persisting anything', function () {
    $user = orderShippingAdmin(['order.update']);
    fakeBiteshipSenderConfig();
    $order = shippingTestOrder();

    Http::fake([
        'api.biteship.com/v1/orders' => Http::response(['success' => false, 'error' => 'invalid courier'], 400),
    ]);

    $response = $this->actingAs($user)->post(
        route('backoffice.order.shipping-create', ['id' => $order->id]),
        [
            'destination_area_id' => 'x',
            'weight_gram' => 1000,
            'courier_code' => 'jne',
            'courier_service_code' => 'reg',
        ]
    );

    $response->assertSessionHasErrors('errors');

    $order->refresh();
    expect($order->biteship_order_id)->toBeNull();
});

test('cancelling a shipment clears the shipment fields but keeps the destination area', function () {
    $user = orderShippingAdmin(['order.update']);
    fakeBiteshipSenderConfig();
    $order = shippingTestOrder();
    $order->update([
        'biteship_order_id' => 'bts-order-1',
        'tracking_number' => 'WYB-1',
        'courier_code' => 'jne',
        'courier_name' => 'JNE',
        'courier_service' => 'Regular',
        'courier_etd' => '2-3 days',
        'shipping_cost' => 25000,
        'shipping_area_id' => 'IDNP1IDNC1IDND1IDZ10110',
        'shipping_area_name' => 'Jakarta Selatan',
    ]);

    Http::fake([
        'api.biteship.com/v1/orders/bts-order-1/cancel' => Http::response([
            'success' => true,
            'id' => 'bts-order-1',
            'status' => 'cancelled',
        ], 200),
    ]);

    $response = $this->actingAs($user)->post(
        route('backoffice.order.shipping-cancel', ['id' => $order->id]),
        ['cancellation_reason_code' => 'change_courier']
    );

    $response->assertRedirect();
    $response->assertSessionDoesntHaveErrors();

    $order->refresh();
    expect($order->biteship_order_id)->toBeNull();
    expect($order->tracking_number)->toBeNull();
    expect($order->courier_code)->toBeNull();
    expect($order->courier_service)->toBeNull();
    expect($order->shipping_cost)->toBeNull();
    // Destination is preserved so staff don't have to re-search the area.
    expect($order->shipping_area_id)->toBe('IDNP1IDNC1IDND1IDZ10110');
    expect($order->shipping_area_name)->toBe('Jakarta Selatan');

    Http::assertSent(fn ($request) => $request['cancellation_reason_code'] === 'change_courier');
});

test('cancelling is refused when the order has no Biteship shipment', function () {
    $user = orderShippingAdmin(['order.update']);
    fakeBiteshipSenderConfig();
    $order = shippingTestOrder();

    Http::fake();

    $response = $this->actingAs($user)->post(
        route('backoffice.order.shipping-cancel', ['id' => $order->id]),
        ['cancellation_reason_code' => 'change_courier']
    );

    $response->assertSessionHasErrors('errors');
    Http::assertNothingSent();
});

test('cancellation reason code must be one of the known values', function () {
    $user = orderShippingAdmin(['order.update']);
    $order = shippingTestOrder();
    $order->update(['biteship_order_id' => 'bts-order-1']);

    $response = $this->actingAs($user)->post(
        route('backoffice.order.shipping-cancel', ['id' => $order->id]),
        ['cancellation_reason_code' => 'not_a_real_reason']
    );

    $response->assertSessionHasErrors('cancellation_reason_code');
});

test('cancellation reason text is required when the code is others', function () {
    $user = orderShippingAdmin(['order.update']);
    $order = shippingTestOrder();
    $order->update(['biteship_order_id' => 'bts-order-1']);

    $response = $this->actingAs($user)->post(
        route('backoffice.order.shipping-cancel', ['id' => $order->id]),
        ['cancellation_reason_code' => 'others']
    );

    $response->assertSessionHasErrors('cancellation_reason');
});
