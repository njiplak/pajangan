<?php

use App\Models\BundleItem;
use App\Models\Order;
use App\Models\Product;
use App\Models\User;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;
use Spatie\Permission\Models\Permission;

function bundleCheckoutPayload(): array
{
    return [
        'customer_name' => 'Yohana Wenda',
        'customer_email' => 'yohana@example.com',
        'customer_phone' => '081234567890',
        'shipping_address' => 'Jl. Sentani No. 9',
        'shipping_city' => 'Jayapura',
        'shipping_province' => 'Papua',
        'shipping_postal_code' => '99111',
        'destination_area_id' => 'IDNP1IDNC1IDND1IDZ10110',
        'destination_area_name' => 'Jayapura, Papua',
        'courier_code' => 'jne',
        'courier_service_code' => 'reg',
        'notes' => null,
    ];
}

function fakeBundleRate(int $price = 15000): void
{
    config([
        'services.biteship.api_key' => 'biteship_test.abc',
        'services.biteship.base_url' => 'https://api.biteship.com',
        'services.biteship.origin_area_id' => 'IDNP6IDNC148IDND854IDZ10730',
        'services.biteship.couriers' => 'jne,jnt',
    ]);

    Http::fake([
        'api.biteship.com/v1/rates/couriers' => Http::response([
            'success' => true,
            'pricing' => [[
                'courier_code' => 'jne',
                'courier_name' => 'JNE',
                'courier_service_code' => 'reg',
                'courier_service_name' => 'Regular',
                'price' => $price,
                'duration' => '2-3 days',
                'available_collection_method' => ['pickup', 'drop_off'],
            ]],
        ], 200),
    ]);
}

function bundleAdmin(array $permissions): User
{
    $user = User::factory()->create();

    foreach ($permissions as $permission) {
        Permission::firstOrCreate(['name' => $permission, 'guard_name' => 'web']);
        $user->givePermissionTo($permission);
    }

    return $user;
}

/**
 * @param  array<int, array{0: Product, 1: int}>  $components
 */
function makeBundle(string $name, int $price, array $components): Product
{
    $bundle = Product::create([
        'name' => $name,
        'price' => $price,
        'stock' => 0,
        'weight_gram' => 0,
        'is_active' => true,
        'is_bundle' => true,
    ]);

    foreach ($components as [$product, $quantity]) {
        BundleItem::create([
            'bundle_id' => $bundle->id,
            'product_id' => $product->id,
            'quantity' => $quantity,
        ]);
    }

    return $bundle->fresh('bundleItems.product');
}

function makeComponent(string $name, int $price, int $stock, int $weight = 500, bool $active = true): Product
{
    return Product::create([
        'name' => $name,
        'price' => $price,
        'stock' => $stock,
        'weight_gram' => $weight,
        'is_active' => $active,
    ]);
}

test('a bundle sells only as many units as its scarcest component allows', function () {
    $coffee = makeComponent('Kopi Wamena', 60000, 7);
    $noken = makeComponent('Noken Kecil', 120000, 2);

    // 2 coffee + 1 noken per bundle => coffee allows 3, noken allows 2.
    $bundle = makeBundle('Paket Oleh-oleh', 200000, [[$coffee, 2], [$noken, 1]]);

    expect($bundle->availableStock())->toBe(2);
});

test('a bundle with an inactive component cannot be sold', function () {
    $coffee = makeComponent('Kopi Wamena', 60000, 10);
    $retired = makeComponent('Gantungan Kunci', 15000, 10, 100, false);

    $bundle = makeBundle('Paket Campur', 80000, [[$coffee, 1], [$retired, 1]]);

    expect($bundle->availableStock())->toBe(0);
});

test('an empty bundle is not treated as unlimited stock', function () {
    $bundle = makeBundle('Paket Kosong', 50000, []);

    expect($bundle->availableStock())->toBe(0);
});

test('bundle weight is the sum of its contents, not its own column', function () {
    $coffee = makeComponent('Kopi Wamena', 60000, 10, 250);
    $noken = makeComponent('Noken Kecil', 120000, 10, 800);

    $bundle = makeBundle('Paket Oleh-oleh', 200000, [[$coffee, 2], [$noken, 1]]);

    expect($bundle->shippingWeightGram())->toBe(1300);
});

test('the cart clamps a bundle to its derived stock', function () {
    $coffee = makeComponent('Kopi Wamena', 60000, 5);
    $noken = makeComponent('Noken Kecil', 120000, 1);

    $bundle = makeBundle('Paket Oleh-oleh', 200000, [[$coffee, 1], [$noken, 1]]);

    $this->post(route('cart.store'), ['product_id' => $bundle->id, 'quantity' => 4]);

    $this->get(route('cart.index'))->assertInertia(fn ($page) => $page
        ->where('cart.items.0.quantity', 1)
        ->where('cart.items.0.stock', 1)
        ->where('cart.items.0.is_bundle', true));
});

test('checkout draws down component stock and quotes the combined weight', function () {
    fakeBundleRate();

    $coffee = makeComponent('Kopi Wamena', 60000, 10, 250);
    $noken = makeComponent('Noken Kecil', 120000, 4, 800);

    $bundle = makeBundle('Paket Oleh-oleh', 200000, [[$coffee, 2], [$noken, 1]]);

    $this->post(route('cart.store'), ['product_id' => $bundle->id, 'quantity' => 2]);
    $this->post(route('checkout.store'), bundleCheckoutPayload());

    $order = Order::first();

    expect($order)->not->toBeNull();
    expect($order->subtotal)->toBe(400000);
    expect($order->items)->toHaveCount(1);
    expect($order->items->first()->product_name)->toBe('Paket Oleh-oleh');

    // 2 bundles => 4 coffee, 2 noken.
    expect($coffee->fresh()->stock)->toBe(6);
    expect($noken->fresh()->stock)->toBe(2);

    // The bundle row itself owns no stock and must stay untouched.
    expect($bundle->fresh()->stock)->toBe(0);

    Http::assertSent(function ($request) {
        if (! str_contains($request->url(), '/v1/rates/couriers')) {
            return false;
        }

        // (2 * 250 + 800) * 2 bundles
        expect($request['items'][0]['weight'])->toBe(2600);

        return true;
    });
});

test('a bundle and its own component in one cart cannot together oversell the component', function () {
    fakeBundleRate();

    // Only 3 bags exist. The bundle needs 2, the standalone line wants 2.
    // Each line passes on its own; together they do not.
    $coffee = makeComponent('Kopi Wamena', 60000, 3);
    $bundle = makeBundle('Paket Kopi', 110000, [[$coffee, 2]]);

    $this->post(route('cart.store'), ['product_id' => $bundle->id, 'quantity' => 1]);
    $this->post(route('cart.store'), ['product_id' => $coffee->id, 'quantity' => 2]);

    $response = $this->post(route('checkout.store'), bundleCheckoutPayload());

    $response->assertSessionHasErrors('cart');
    expect(Order::count())->toBe(0);
    expect($coffee->fresh()->stock)->toBe(3);
});

test('the storefront shows a bundle contents and what it saves', function () {
    $coffee = makeComponent('Kopi Wamena', 60000, 10);
    $noken = makeComponent('Noken Kecil', 120000, 10);

    $bundle = makeBundle('Paket Oleh-oleh', 200000, [[$coffee, 2], [$noken, 1]]);

    $this->get(route('products.show', $bundle->slug))
        ->assertInertia(fn ($page) => $page
            ->where('product.is_bundle', true)
            ->where('product.stock', 5)
            ->where('product.components_total', 240000)
            ->where('product.bundle_items.0.name', 'Kopi Wamena')
            ->where('product.bundle_items.0.quantity', 2));
});

test('a bundle cannot contain another bundle', function () {
    $user = bundleAdmin(['product.create']);
    $coffee = makeComponent('Kopi Wamena', 60000, 10);
    $inner = makeBundle('Paket Dalam', 100000, [[$coffee, 1]]);

    $this->actingAs($user)
        ->post(route('backoffice.product.store'), [
            'name' => 'Paket Luar',
            'price' => 150000,
            'is_active' => true,
            'is_bundle' => true,
            'bundle_items' => [['product_id' => $inner->id, 'quantity' => 1]],
        ])
        ->assertSessionHasErrors('bundle_items.0.product_id');

    expect(Product::where('name', 'Paket Luar')->exists())->toBeFalse();
});

test('creating a bundle stores its components and zeroes its own stock and weight', function () {
    $user = bundleAdmin(['product.create']);
    $coffee = makeComponent('Kopi Wamena', 60000, 10);
    $noken = makeComponent('Noken Kecil', 120000, 4);

    Storage::fake('public');

    // An image is supplied because BaseService::create unconditionally reads
    // one from the request; see the note in the accompanying report.
    $this->actingAs($user)->post(route('backoffice.product.store'), [
        'name' => 'Paket Oleh-oleh',
        'price' => 200000,
        'stock' => 999,
        'weight_gram' => 9999,
        'is_active' => true,
        'is_bundle' => true,
        'images' => [UploadedFile::fake()->image('paket.jpg')],
        'bundle_items' => [
            ['product_id' => $coffee->id, 'quantity' => 2],
            ['product_id' => $noken->id, 'quantity' => 1],
        ],
    ]);

    $bundle = Product::where('name', 'Paket Oleh-oleh')->with('bundleItems.product')->first();

    expect($bundle)->not->toBeNull();
    expect($bundle->is_bundle)->toBeTrue();
    expect($bundle->stock)->toBe(0);
    expect($bundle->weight_gram)->toBe(0);
    expect($bundle->bundleItems)->toHaveCount(2);
    expect($bundle->availableStock())->toBe(4);
});

test('a product still inside a bundle cannot be deleted', function () {
    $user = bundleAdmin(['product.delete']);
    $coffee = makeComponent('Kopi Wamena', 60000, 10);
    makeBundle('Paket Oleh-oleh', 200000, [[$coffee, 2]]);

    $this->actingAs($user)
        ->delete(route('backoffice.product.destroy', $coffee->id))
        ->assertSessionHasErrors('errors');

    expect(Product::find($coffee->id))->not->toBeNull();
});

test('deleting a bundle leaves its components alone', function () {
    $user = bundleAdmin(['product.delete']);
    $coffee = makeComponent('Kopi Wamena', 60000, 10);
    $bundle = makeBundle('Paket Oleh-oleh', 200000, [[$coffee, 2]]);

    $this->actingAs($user)->delete(route('backoffice.product.destroy', $bundle->id));

    expect(Product::find($bundle->id))->toBeNull();
    expect(Product::find($coffee->id))->not->toBeNull();
    expect(BundleItem::where('bundle_id', $bundle->id)->count())->toBe(0);
});
