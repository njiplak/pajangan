<?php

use App\Models\Customer;
use App\Models\CustomerAddress;
use App\Models\Order;
use App\Models\OrderActivity;
use App\Models\OrderItem;
use App\Models\User;
use Spatie\Permission\Models\Permission;

function toolsStaff(array $permissions = ['order.view', 'order.update']): User
{
    $user = User::factory()->create(['name' => 'Agus Kasir']);

    foreach ($permissions as $permission) {
        Permission::firstOrCreate(['name' => $permission, 'guard_name' => 'web']);
        $user->givePermissionTo($permission);
    }

    return $user;
}

function toolsOrder(array $overrides = []): Order
{
    $order = Order::create(array_merge([
        'order_number' => 'ORD-TL-001',
        'customer_name' => 'Yohana Wenda',
        'customer_email' => 'yohana@example.com',
        'customer_phone' => '0812',
        'shipping_address' => 'Jl. Lama No. 1',
        'shipping_city' => 'Jayapura',
        'shipping_province' => 'Papua',
        'status' => Order::STATUS_PAID,
        'subtotal' => 120000,
        'shipping_cost' => 15000,
        'total' => 135000,
    ], $overrides));

    OrderItem::create([
        'order_id' => $order->id, 'product_id' => null, 'product_name' => 'Kopi Wamena',
        'unit_price' => 60000, 'quantity' => 2, 'subtotal' => 120000,
    ]);

    return $order;
}

function toolsDetails(array $overrides = []): array
{
    return array_merge([
        'customer_name' => 'Yohana Wenda',
        'customer_email' => 'yohana@example.com',
        'customer_phone' => '0812',
        'shipping_address' => 'Jl. Baru No. 2',
        'shipping_city' => 'Jayapura',
        'shipping_province' => 'Papua',
        'shipping_postal_code' => '99111',
    ], $overrides);
}

// --- Correcting an order's details ---------------------------------------

test('staff can correct the address before a shipment exists, and the change is logged', function () {
    $staff = toolsStaff();
    $order = toolsOrder();

    $this->actingAs($staff)->put(route('backoffice.order.update-details', $order->id), toolsDetails())
        ->assertSessionHasNoErrors();

    expect($order->fresh()->shipping_address)->toBe('Jl. Baru No. 2');
    $activity = OrderActivity::where('action', 'details')->sole();
    expect($activity->user_id)->toBe($staff->id);
    expect($activity->description)->toContain('Jl. Lama No. 1')->toContain('Jl. Baru No. 2');
});

test('saving unchanged details logs nothing', function () {
    $staff = toolsStaff();
    $order = toolsOrder(['shipping_postal_code' => '99111', 'shipping_address' => 'Jl. Baru No. 2']);

    $this->actingAs($staff)->put(route('backoffice.order.update-details', $order->id), toolsDetails())
        ->assertSessionHasNoErrors();

    expect(OrderActivity::count())->toBe(0);
});

test('details cannot be edited once a shipment carries them, or the order is finished', function (array $state) {
    $staff = toolsStaff();
    $order = toolsOrder($state);

    $this->actingAs($staff)->put(route('backoffice.order.update-details', $order->id), toolsDetails())
        ->assertSessionHasErrors('errors');

    expect($order->fresh()->shipping_address)->toBe('Jl. Lama No. 1');
})->with([
    'shipment created' => [['biteship_order_id' => 'bts-1']],
    'shipped' => [['status' => Order::STATUS_SHIPPED]],
    'completed' => [['status' => Order::STATUS_COMPLETED]],
    'cancelled' => [['status' => Order::STATUS_CANCELLED]],
]);

test('details must be valid', function () {
    $staff = toolsStaff();
    $order = toolsOrder();

    $this->actingAs($staff)->put(route('backoffice.order.update-details', $order->id), toolsDetails([
        'customer_name' => '', 'customer_email' => 'not-an-email', 'shipping_address' => '',
    ]))->assertSessionHasErrors(['customer_name', 'customer_email', 'shipping_address']);
});

test('an order may have no email address, for customers who order by chat', function () {
    $staff = toolsStaff();
    $order = toolsOrder();

    $this->actingAs($staff)->put(route('backoffice.order.update-details', $order->id), toolsDetails(['customer_email' => null]))
        ->assertSessionHasNoErrors();

    expect($order->fresh()->customer_email)->toBeNull();
});

test('editing details requires order.update', function () {
    $viewer = toolsStaff(['order.view']);
    $order = toolsOrder();

    $this->actingAs($viewer)->put(route('backoffice.order.update-details', $order->id), toolsDetails())->assertForbidden();
});

// --- Printing -------------------------------------------------------------

test('the printable slip lists the recipient and every item', function () {
    $staff = toolsStaff(['order.view']);
    $order = toolsOrder(['tracking_number' => 'JNE777', 'courier_name' => 'JNE']);

    $this->actingAs($staff)->get(route('backoffice.order.print', $order->id))
        ->assertOk()
        ->assertSee('ORD-TL-001')
        ->assertSee('Jl. Lama No. 1')
        ->assertSee('Kopi Wamena')
        ->assertSee('JNE777')
        ->assertSee('Rp 135.000');
});

test('the printable slip escapes what customers typed', function () {
    $staff = toolsStaff(['order.view']);
    $order = toolsOrder(['customer_name' => '<script>alert(1)</script>']);

    $this->actingAs($staff)->get(route('backoffice.order.print', $order->id))
        ->assertOk()
        ->assertDontSee('<script>alert(1)</script>', false);
});

test('a missing order has no slip', function () {
    $this->actingAs(toolsStaff(['order.view']))->get(route('backoffice.order.print', 999))->assertNotFound();
});

// --- CSV export -----------------------------------------------------------

test('orders export to CSV with the same filters as the list', function () {
    $staff = toolsStaff(['order.view']);
    toolsOrder();
    toolsOrder(['order_number' => 'ORD-TL-002', 'status' => Order::STATUS_PENDING]);

    $response = $this->actingAs($staff)->get(route('backoffice.order.export', ['filter' => ['status' => 'paid']]));

    $response->assertOk();
    expect($response->headers->get('content-type'))->toContain('text/csv');
    $csv = $response->streamedContent();
    $lines = array_values(array_filter(explode("\n", trim($csv))));
    expect($lines)->toHaveCount(2);
    expect($lines[0])->toContain('order_number');
    expect($lines[1])->toContain('ORD-TL-001')->toContain('135000');
    expect($csv)->not->toContain('ORD-TL-002');
});

test('exported cells cannot run as spreadsheet formulas', function () {
    $staff = toolsStaff(['order.view']);
    toolsOrder(['customer_name' => '=HYPERLINK("http://evil")']);

    $csv = $this->actingAs($staff)->get(route('backoffice.order.export'))->streamedContent();

    expect($csv)->toContain("'=HYPERLINK");
});

test('exporting requires order.view', function () {
    $user = User::factory()->create();

    $this->actingAs($user)->get(route('backoffice.order.export'))->assertForbidden();
});

// --- Customers ------------------------------------------------------------

test('staff can search customers', function () {
    $staff = toolsStaff(['order.view']);
    Customer::create(['name' => 'Yohana Wenda', 'email' => 'yohana@example.com', 'phone' => '0812']);
    Customer::create(['name' => 'Budi Santoso', 'email' => 'budi@example.com']);

    $items = $this->actingAs($staff)
        ->getJson(route('backoffice.customer.fetch', ['filter' => ['search' => 'yohana']]))
        ->assertOk()
        ->json('items');

    expect(collect($items)->pluck('email')->all())->toBe(['yohana@example.com']);
});

test('a customer page shows their addresses and the orders their account can see', function () {
    $staff = toolsStaff(['order.view']);
    $customer = Customer::create(['name' => 'Yohana Wenda', 'email' => 'yohana@example.com', 'email_verified_at' => now()]);
    CustomerAddress::create([
        'customer_id' => $customer->id, 'recipient_name' => 'Yohana', 'phone' => '0812', 'address' => 'Jl. Rumah',
        'city' => 'Jayapura', 'province' => 'Papua', 'destination_area_id' => 'A1', 'destination_area_name' => 'Jayapura',
    ]);
    toolsOrder(['customer_id' => $customer->id]);
    toolsOrder(['order_number' => 'ORD-GUEST', 'customer_id' => null]);
    toolsOrder(['order_number' => 'ORD-OTHER', 'customer_email' => 'other@example.com']);

    $this->actingAs($staff)->get(route('backoffice.customer.show', $customer->id))
        ->assertInertia(fn ($page) => $page
            ->where('customer.email', 'yohana@example.com')
            ->has('addresses', 1)
            ->has('orders', 2)
            ->where('orders', fn ($orders) => collect($orders)->pluck('order_number')->sort()->values()->all() === ['ORD-GUEST', 'ORD-TL-001']));
});

test('customer pages require order.view', function () {
    $user = User::factory()->create();

    $this->actingAs($user)->get(route('backoffice.customer.index'))->assertForbidden();
});
