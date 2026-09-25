<?php

use App\Contract\Payment\PaymentStatus;
use App\Mail\OrderPlacedMail;
use App\Mail\PaymentReceivedMail;
use App\Mail\StaffLowStockMail;
use App\Mail\StaffOrderPaidMail;
use App\Models\BundleItem;
use App\Models\Customer;
use App\Models\Order;
use App\Models\OrderActivity;
use App\Models\Product;
use App\Models\StockMovement;
use App\Models\User;
use Illuminate\Support\Facades\Mail;
use Spatie\Permission\Models\Permission;

function manualStaff(array $permissions = ['order.view', 'order.update']): User
{
    $user = User::factory()->create(['email' => 'kasir@example.com']);

    foreach ($permissions as $permission) {
        Permission::firstOrCreate(['name' => $permission, 'guard_name' => 'web']);
        $user->givePermissionTo($permission);
    }

    return $user;
}

function manualProduct(string $name = 'Kopi Wamena', int $stock = 10, int $price = 60000, bool $active = true): Product
{
    return Product::create(['name' => $name, 'price' => $price, 'stock' => $stock, 'weight_gram' => 250, 'is_active' => $active]);
}

function manualPayload(array $items, array $overrides = []): array
{
    return array_merge([
        'customer_name' => 'Mama Yosina',
        'customer_email' => 'yosina@example.com',
        'customer_phone' => '081299990000',
        'shipping_address' => 'Jl. Pasar Youtefa',
        'shipping_city' => 'Jayapura',
        'shipping_province' => 'Papua',
        'shipping_postal_code' => '99224',
        'notes' => 'Pesan lewat WhatsApp',
        'items' => $items,
        'shipping_cost' => 20000,
        'courier_name' => 'Antar sendiri',
        'paid' => false,
        'payment_note' => null,
    ], $overrides);
}

test('staff can enter a paid chat order: stock is drawn, it is logged, and the customer gets a receipt', function () {
    Mail::fake();
    $staff = manualStaff();
    $product = manualProduct(stock: 10);

    $this->actingAs($staff)->post(route('backoffice.order.store'), manualPayload(
        [['product_id' => $product->id, 'quantity' => 3]],
        ['paid' => true, 'payment_note' => 'Transfer BCA'],
    ))->assertSessionHasNoErrors()->assertRedirect();

    $order = Order::sole();
    expect($order->status)->toBe(Order::STATUS_PAID);
    expect($order->payment_status)->toBe(PaymentStatus::PAID);
    expect($order->paid_at)->not->toBeNull();
    expect($order->subtotal)->toBe(180000);
    expect($order->shipping_cost)->toBe(20000);
    expect($order->total)->toBe(200000);
    expect($order->stock_draw)->toBe([$product->id => 3]);
    expect($order->items()->sole()->only('product_name', 'quantity', 'unit_price'))
        ->toBe(['product_name' => 'Kopi Wamena', 'quantity' => 3, 'unit_price' => 60000]);

    expect($product->fresh()->stock)->toBe(7);
    $movement = StockMovement::sole();
    expect($movement->only('reason', 'change', 'user_id', 'order_id'))->toBe([
        'reason' => StockMovement::REASON_MANUAL_ORDER, 'change' => -3, 'user_id' => $staff->id, 'order_id' => $order->id,
    ]);

    $created = OrderActivity::where('action', 'created')->sole();
    expect($created->user_id)->toBe($staff->id);
    expect($created->description)->toContain('Transfer BCA');

    Mail::assertSent(PaymentReceivedMail::class, fn ($mail) => $mail->hasTo('yosina@example.com'));
    Mail::assertNotSent(OrderPlacedMail::class);
    Mail::assertNotSent(StaffOrderPaidMail::class);
});

test('an unpaid chat order waits for payment and the customer gets the order link', function () {
    Mail::fake();
    $staff = manualStaff();
    $product = manualProduct();

    $this->actingAs($staff)->post(route('backoffice.order.store'), manualPayload([['product_id' => $product->id, 'quantity' => 1]]))
        ->assertSessionHasNoErrors();

    $order = Order::sole();
    expect($order->status)->toBe(Order::STATUS_PENDING);
    expect($order->paid_at)->toBeNull();
    Mail::assertSent(OrderPlacedMail::class, 1);
});

test('a chat order without an email is still taken, and nobody is mailed', function () {
    Mail::fake();
    $staff = manualStaff();
    $product = manualProduct();

    $this->actingAs($staff)->post(route('backoffice.order.store'), manualPayload(
        [['product_id' => $product->id, 'quantity' => 1]],
        ['customer_email' => null, 'paid' => true],
    ))->assertSessionHasNoErrors();

    expect(Order::sole()->customer_email)->toBeNull();
    Mail::assertNothingSent();
});

test('a chat order lands in the account of a customer with that email', function () {
    $staff = manualStaff();
    $customer = Customer::create(['name' => 'Mama Yosina', 'email' => 'Yosina@Example.com']);
    $product = manualProduct();

    $this->actingAs($staff)->post(route('backoffice.order.store'), manualPayload([['product_id' => $product->id, 'quantity' => 1]]))
        ->assertSessionHasNoErrors();

    expect(Order::sole()->customer_id)->toBe($customer->id);
});

test('a bundle in a chat order draws its components', function () {
    $staff = manualStaff();
    $beans = manualProduct('Biji Kopi', 10);
    $bundle = Product::create(['name' => 'Paket Kopi', 'price' => 100000, 'stock' => 0, 'weight_gram' => 0, 'is_active' => true, 'is_bundle' => true]);
    BundleItem::create(['bundle_id' => $bundle->id, 'product_id' => $beans->id, 'quantity' => 2]);

    $this->actingAs($staff)->post(route('backoffice.order.store'), manualPayload([['product_id' => $bundle->id, 'quantity' => 2]]))
        ->assertSessionHasNoErrors();

    expect($beans->fresh()->stock)->toBe(6);
});

test('a chat order asking for more than is in stock is refused and nothing changes', function () {
    Mail::fake();
    $staff = manualStaff();
    $product = manualProduct(stock: 2);

    $this->actingAs($staff)->post(route('backoffice.order.store'), manualPayload([['product_id' => $product->id, 'quantity' => 3]]))
        ->assertSessionHasErrors('items');

    expect(Order::count())->toBe(0);
    expect($product->fresh()->stock)->toBe(2);
    expect(StockMovement::count())->toBe(0);
    Mail::assertNothingSent();
});

test('an inactive product cannot be put in a chat order', function () {
    $staff = manualStaff();
    $product = manualProduct(active: false);

    $this->actingAs($staff)->post(route('backoffice.order.store'), manualPayload([['product_id' => $product->id, 'quantity' => 1]]))
        ->assertSessionHasErrors('items');

    expect(Order::count())->toBe(0);
});

test('a chat order must have items, a name, a phone and a non-negative shipping cost', function () {
    $staff = manualStaff();

    $this->actingAs($staff)->post(route('backoffice.order.store'), manualPayload([], [
        'customer_name' => '', 'customer_phone' => '', 'shipping_cost' => -1,
    ]))->assertSessionHasErrors(['items', 'customer_name', 'customer_phone', 'shipping_cost']);
});

test('a chat order that runs a product low tells staff', function () {
    Mail::fake();
    $staff = manualStaff();
    $product = manualProduct(stock: 12);

    $this->actingAs($staff)->post(route('backoffice.order.store'), manualPayload([['product_id' => $product->id, 'quantity' => 3]]))
        ->assertSessionHasNoErrors();

    Mail::assertSent(StaffLowStockMail::class, 1);
});

test('entering orders requires order.update', function () {
    $viewer = manualStaff(['order.view']);
    $product = manualProduct();

    $this->actingAs($viewer)->get(route('backoffice.order.create'))->assertForbidden();
    $this->actingAs($viewer)->post(route('backoffice.order.store'), manualPayload([['product_id' => $product->id, 'quantity' => 1]]))
        ->assertForbidden();
});
