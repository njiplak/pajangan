<?php

use App\Contract\Payment\PaymentStatus;
use App\Mail\OrderCancelledMail;
use App\Models\BundleItem;
use App\Models\Order;
use App\Models\Product;
use App\Models\Setting;
use App\Models\User;
use App\Service\Order\OrderStockReleaser;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Mail;
use Spatie\Permission\Models\Permission;

beforeEach(fn () => fakeDefaultPaymentGateway());

function releasePayload(): array
{
    return [
        'customer_name' => 'Yohana Wenda',
        'customer_email' => 'yohana@example.com',
        'customer_phone' => '081234567890',
        'shipping_address' => 'Jl. Sentani No. 9',
        'shipping_city' => 'Jayapura',
        'shipping_province' => 'Papua',
        'shipping_postal_code' => '99111',
        'destination_area_id' => 'AREA-1',
        'destination_area_name' => 'Jayapura',
        'courier_code' => 'jne',
        'courier_service_code' => 'reg',
        'notes' => null,
    ];
}

function fakeReleaseRate(): void
{
    config([
        'services.biteship.api_key' => 'biteship_test.abc',
        'services.biteship.base_url' => 'https://api.biteship.com',
        'services.biteship.origin_area_id' => 'ORIGIN-1',
        'services.biteship.couriers' => 'jne',
    ]);

    Http::fake([
        'api.biteship.com/v1/rates/couriers' => Http::response([
            'success' => true,
            'pricing' => [[
                'courier_code' => 'jne',
                'courier_name' => 'JNE',
                'courier_service_code' => 'reg',
                'courier_service_name' => 'Regular',
                'price' => 15000,
                'duration' => '2-3 days',
                'available_collection_method' => ['pickup'],
            ]],
        ], 200),
    ]);
}

function releaseProduct(string $name = 'Kopi Wamena', int $stock = 10): Product
{
    return Product::create([
        'name' => $name,
        'price' => 60000,
        'stock' => $stock,
        'weight_gram' => 250,
        'is_active' => true,
    ]);
}

test('checkout records exactly what it took out of stock', function () {
    fakeReleaseRate();
    $product = releaseProduct();

    $this->post(route('cart.store'), ['product_id' => $product->id, 'quantity' => 3]);
    $this->post(route('checkout.store'), releasePayload());

    expect(Order::first()->stock_draw)->toBe([(string) $product->id => 3]);
});

test('an unpaid order past the hold window is cancelled and its stock returns', function () {
    Mail::fake();
    fakeReleaseRate();

    $product = releaseProduct(stock: 10);
    $this->post(route('cart.store'), ['product_id' => $product->id, 'quantity' => 4]);
    $this->post(route('checkout.store'), releasePayload());

    expect($product->fresh()->stock)->toBe(6);

    $order = Order::first();
    Order::query()->whereKey($order->id)->update(['created_at' => now()->subHours(25)]);

    $this->artisan('orders:expire-unpaid')->assertExitCode(0);

    $order->refresh();
    expect($order->status)->toBe(Order::STATUS_CANCELLED);
    expect($order->stock_released_at)->not->toBeNull();
    expect($product->fresh()->stock)->toBe(10);

    Mail::assertSent(OrderCancelledMail::class, 1);
});

test('an order still inside the hold window is left alone', function () {
    fakeReleaseRate();

    $product = releaseProduct(stock: 10);
    $this->post(route('cart.store'), ['product_id' => $product->id, 'quantity' => 4]);
    $this->post(route('checkout.store'), releasePayload());

    $order = Order::first();
    Order::query()->whereKey($order->id)->update(['created_at' => now()->subHours(3)]);

    $this->artisan('orders:expire-unpaid')->assertExitCode(0);

    expect($order->fresh()->status)->toBe(Order::STATUS_PENDING);
    expect($product->fresh()->stock)->toBe(6);
});

test('the hold window is read from settings', function () {
    fakeReleaseRate();
    Setting::updateOrCreate(['key' => 'order_unpaid_hold_hours'], ['value' => '1']);

    $product = releaseProduct(stock: 10);
    $this->post(route('cart.store'), ['product_id' => $product->id, 'quantity' => 2]);
    $this->post(route('checkout.store'), releasePayload());

    Order::query()->update(['created_at' => now()->subHours(2)]);

    $this->artisan('orders:expire-unpaid')->assertExitCode(0);

    expect(Order::first()->status)->toBe(Order::STATUS_CANCELLED);
    expect($product->fresh()->stock)->toBe(10);
});

test('a paid order is never expired', function () {
    fakeReleaseRate();

    $product = releaseProduct(stock: 10);
    $this->post(route('cart.store'), ['product_id' => $product->id, 'quantity' => 2]);
    $this->post(route('checkout.store'), releasePayload());

    $order = Order::first();
    Order::query()->whereKey($order->id)->update([
        'created_at' => now()->subHours(48),
        'payment_status' => PaymentStatus::PAID,
        'status' => Order::STATUS_PAID,
    ]);

    $this->artisan('orders:expire-unpaid')->assertExitCode(0);

    expect($order->fresh()->status)->toBe(Order::STATUS_PAID);
    expect($product->fresh()->stock)->toBe(8);
});

test('a bundle order returns stock to its components, not the bundle', function () {
    fakeReleaseRate();

    $coffee = releaseProduct('Kopi Komponen', 10);
    $noken = releaseProduct('Noken Komponen', 4);

    $bundle = Product::create([
        'name' => 'Paket Oleh-oleh',
        'price' => 200000,
        'stock' => 0,
        'weight_gram' => 0,
        'is_active' => true,
        'is_bundle' => true,
    ]);
    BundleItem::create(['bundle_id' => $bundle->id, 'product_id' => $coffee->id, 'quantity' => 2]);
    BundleItem::create(['bundle_id' => $bundle->id, 'product_id' => $noken->id, 'quantity' => 1]);

    $this->post(route('cart.store'), ['product_id' => $bundle->id, 'quantity' => 2]);
    $this->post(route('checkout.store'), releasePayload());

    expect($coffee->fresh()->stock)->toBe(6);
    expect($noken->fresh()->stock)->toBe(2);

    Order::query()->update(['created_at' => now()->subHours(25)]);
    $this->artisan('orders:expire-unpaid');

    expect($coffee->fresh()->stock)->toBe(10);
    expect($noken->fresh()->stock)->toBe(4);
    expect($bundle->fresh()->stock)->toBe(0);
});

test('a bundle edited after the order still returns exactly what was taken', function () {
    fakeReleaseRate();

    $coffee = releaseProduct('Kopi Komponen', 10);
    $noken = releaseProduct('Noken Komponen', 4);

    $bundle = Product::create([
        'name' => 'Paket Oleh-oleh',
        'price' => 200000,
        'stock' => 0,
        'weight_gram' => 0,
        'is_active' => true,
        'is_bundle' => true,
    ]);
    BundleItem::create(['bundle_id' => $bundle->id, 'product_id' => $coffee->id, 'quantity' => 2]);

    $this->post(route('cart.store'), ['product_id' => $bundle->id, 'quantity' => 1]);
    $this->post(route('checkout.store'), releasePayload());

    expect($coffee->fresh()->stock)->toBe(8);

    // Staff rework the bundle after the order was placed.
    BundleItem::where('bundle_id', $bundle->id)->delete();
    BundleItem::create(['bundle_id' => $bundle->id, 'product_id' => $noken->id, 'quantity' => 3]);

    Order::query()->update(['created_at' => now()->subHours(25)]);
    $this->artisan('orders:expire-unpaid');

    // The coffee that was actually taken comes back; the noken, which was
    // never part of this order, is untouched.
    expect($coffee->fresh()->stock)->toBe(10);
    expect($noken->fresh()->stock)->toBe(4);
});

test('stock is never released twice', function () {
    fakeReleaseRate();

    $product = releaseProduct(stock: 10);
    $this->post(route('cart.store'), ['product_id' => $product->id, 'quantity' => 3]);
    $this->post(route('checkout.store'), releasePayload());

    $order = Order::first();
    Order::query()->whereKey($order->id)->update(['created_at' => now()->subHours(25)]);

    $releaser = app(OrderStockReleaser::class);

    expect($releaser->release($order))->toBeTrue();
    expect($releaser->release($order->fresh()))->toBeFalse();
    expect($product->fresh()->stock)->toBe(10);

    // And the scheduled command cannot double it either.
    $this->artisan('orders:expire-unpaid');
    expect($product->fresh()->stock)->toBe(10);
});

test('staff cancelling an order returns its stock and tells the customer', function () {
    Mail::fake();
    fakeReleaseRate();

    $product = releaseProduct(stock: 10);
    $this->post(route('cart.store'), ['product_id' => $product->id, 'quantity' => 3]);
    $this->post(route('checkout.store'), releasePayload());

    expect($product->fresh()->stock)->toBe(7);

    $user = User::factory()->create();
    Permission::firstOrCreate(['name' => 'order.update', 'guard_name' => 'web']);
    $user->givePermissionTo('order.update');

    $this->actingAs($user)->put(route('backoffice.order.update-status', Order::first()->id), [
        'status' => Order::STATUS_CANCELLED,
    ]);

    expect($product->fresh()->stock)->toBe(10);
    Mail::assertSent(OrderCancelledMail::class, 1);
});

test('an expired unpaid order tells the customer no charge was made', function () {
    $order = Order::create([
        'order_number' => 'ORD-CXL-UNPAID',
        'customer_name' => 'Yohana', 'customer_email' => 'y@example.com', 'customer_phone' => '0812',
        'shipping_address' => 'Jl. Test', 'shipping_city' => 'Jayapura', 'shipping_province' => 'Papua',
        'status' => Order::STATUS_CANCELLED, 'subtotal' => 1000, 'total' => 1000,
    ]);

    $html = (new OrderCancelledMail($order->load('items')))->render();

    expect($html)->toContain('Tidak ada biaya');
    expect($html)->not->toContain('pengembalian dana');
});

test('a cancelled paid order promises a refund instead of claiming no charge', function () {
    $order = Order::create([
        'order_number' => 'ORD-CXL-PAID',
        'customer_name' => 'Yohana', 'customer_email' => 'y@example.com', 'customer_phone' => '0812',
        'shipping_address' => 'Jl. Test', 'shipping_city' => 'Jayapura', 'shipping_province' => 'Papua',
        'status' => Order::STATUS_CANCELLED, 'subtotal' => 1000, 'total' => 1000,
        'paid_at' => now(),
    ]);

    $html = (new OrderCancelledMail($order->load('items')))->render();

    expect($html)->toContain('pengembalian dana');
    expect($html)->not->toContain('Tidak ada biaya');
});
