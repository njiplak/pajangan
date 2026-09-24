<?php

use App\Models\Product;
use App\Models\Setting;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;

function fakeEstimateRates(array $prices = ['jne' => 45000, 'jnt' => 38000]): void
{
    config([
        'services.biteship.api_key' => 'biteship_test.abc',
        'services.biteship.base_url' => 'https://api.biteship.com',
        'services.biteship.origin_area_id' => 'IDNP6IDNC148IDND854IDZ10730',
        'services.biteship.couriers' => 'jne,jnt',
    ]);

    $pricing = [];

    foreach ($prices as $code => $price) {
        $pricing[] = [
            'courier_code' => $code,
            'courier_name' => strtoupper($code),
            'courier_service_code' => 'reg',
            'courier_service_name' => 'Regular',
            'price' => $price,
            'duration' => '3-5 days',
            'available_collection_method' => ['pickup'],
        ];
    }

    Http::fake([
        'api.biteship.com/v1/rates/couriers' => Http::response([
            'success' => true,
            'pricing' => $pricing,
        ], 200),
    ]);
}

function estimateProduct(array $overrides = []): Product
{
    return Product::create(array_merge([
        'name' => 'Kopi Wamena',
        'price' => 60000,
        'stock' => 10,
        'weight_gram' => 250,
        'is_active' => true,
    ], $overrides));
}

beforeEach(function () {
    Cache::flush();
});

test('no estimate is shown until the visitor says where to ship', function () {
    fakeEstimateRates();
    $product = estimateProduct();

    $response = $this->getJson(route('shipping.estimate', ['product_id' => $product->id]));

    $response->assertOk();
    $response->assertJson(['destination' => null, 'estimate' => null]);

    // Nothing is guessed, so the courier is never called.
    Http::assertNothingSent();
});

test('setting a destination is remembered and returns the cheapest rate for a product', function () {
    fakeEstimateRates(['jne' => 45000, 'jnt' => 38000]);
    $product = estimateProduct();

    $this->post(route('shipping.destination'), [
        'destination_area_id' => 'IDNP9IDNC378IDND5390IDZ40111',
        'destination_area_name' => 'Bandung, Jawa Barat',
    ])->assertRedirect();

    $response = $this->getJson(route('shipping.estimate', ['product_id' => $product->id]));

    $response->assertOk();
    $response->assertJson([
        'destination' => [
            'id' => 'IDNP9IDNC378IDND5390IDZ40111',
            'name' => 'Bandung, Jawa Barat',
        ],
        'estimate' => [
            'courier_name' => 'JNT',
            'price' => 38000,
        ],
    ]);
});

test('the quoted weight scales with quantity', function () {
    fakeEstimateRates();
    $product = estimateProduct(['weight_gram' => 250]);

    $this->post(route('shipping.destination'), [
        'destination_area_id' => 'AREA-1',
        'destination_area_name' => 'Bandung',
    ]);

    $this->getJson(route('shipping.estimate', ['product_id' => $product->id, 'quantity' => 4]))
        ->assertOk();

    Http::assertSent(function ($request) {
        if (! str_contains($request->url(), '/v1/rates/couriers')) {
            return false;
        }

        expect($request['items'][0]['weight'])->toBe(1000);
        expect($request['items'][0]['value'])->toBe(240000);

        return true;
    });
});

test('a bundle is quoted at the summed weight of its contents', function () {
    fakeEstimateRates();

    $coffee = estimateProduct(['name' => 'Kopi Komponen', 'weight_gram' => 250]);
    $noken = estimateProduct(['name' => 'Noken Komponen', 'weight_gram' => 800]);

    $bundle = Product::create([
        'name' => 'Paket Oleh-oleh',
        'price' => 200000,
        'stock' => 0,
        'weight_gram' => 0,
        'is_active' => true,
        'is_bundle' => true,
    ]);

    \App\Models\BundleItem::create(['bundle_id' => $bundle->id, 'product_id' => $coffee->id, 'quantity' => 2]);
    \App\Models\BundleItem::create(['bundle_id' => $bundle->id, 'product_id' => $noken->id, 'quantity' => 1]);

    $this->post(route('shipping.destination'), [
        'destination_area_id' => 'AREA-1',
        'destination_area_name' => 'Bandung',
    ]);

    $this->getJson(route('shipping.estimate', ['product_id' => $bundle->id]))->assertOk();

    Http::assertSent(function ($request) {
        if (! str_contains($request->url(), '/v1/rates/couriers')) {
            return false;
        }

        // 2 * 250 + 800
        expect($request['items'][0]['weight'])->toBe(1300);

        return true;
    });
});

test('without a product the estimate covers the whole cart', function () {
    fakeEstimateRates();

    $coffee = estimateProduct(['name' => 'Kopi', 'weight_gram' => 250]);
    $noken = estimateProduct(['name' => 'Noken', 'weight_gram' => 800, 'price' => 120000]);

    $this->post(route('cart.store'), ['product_id' => $coffee->id, 'quantity' => 2]);
    $this->post(route('cart.store'), ['product_id' => $noken->id, 'quantity' => 1]);

    $this->post(route('shipping.destination'), [
        'destination_area_id' => 'AREA-1',
        'destination_area_name' => 'Bandung',
    ]);

    $this->getJson(route('shipping.estimate'))->assertOk();

    Http::assertSent(function ($request) {
        if (! str_contains($request->url(), '/v1/rates/couriers')) {
            return false;
        }

        // 2 * 250 + 800
        expect($request['items'][0]['weight'])->toBe(1300);
        // 2 * 60000 + 120000
        expect($request['items'][0]['value'])->toBe(240000);

        return true;
    });
});

test('an identical estimate is served from cache instead of re-quoting', function () {
    fakeEstimateRates();
    $product = estimateProduct();

    $this->post(route('shipping.destination'), [
        'destination_area_id' => 'AREA-1',
        'destination_area_name' => 'Bandung',
    ]);

    $this->getJson(route('shipping.estimate', ['product_id' => $product->id]))->assertOk();
    $this->getJson(route('shipping.estimate', ['product_id' => $product->id]))->assertOk();
    $this->getJson(route('shipping.estimate', ['product_id' => $product->id]))->assertOk();

    Http::assertSentCount(1);
});

test('a courier outage leaves the page usable and retries next time', function () {
    config([
        'services.biteship.api_key' => 'biteship_test.abc',
        'services.biteship.base_url' => 'https://api.biteship.com',
        'services.biteship.origin_area_id' => 'ORIGIN-1',
        'services.biteship.couriers' => 'jne',
    ]);

    Http::fake([
        'api.biteship.com/v1/rates/couriers' => Http::response(['success' => false, 'error' => 'boom'], 500),
    ]);

    $product = estimateProduct();

    $this->post(route('shipping.destination'), [
        'destination_area_id' => 'AREA-1',
        'destination_area_name' => 'Bandung',
    ]);

    $this->getJson(route('shipping.estimate', ['product_id' => $product->id]))
        ->assertOk()
        ->assertJson(['estimate' => null]);

    // A failure must not be held for the whole TTL.
    $this->getJson(route('shipping.estimate', ['product_id' => $product->id]))->assertOk();

    Http::assertSentCount(2);
});

test('the estimate endpoint is unavailable in display mode', function () {
    Setting::updateOrCreate(['key' => 'storefront_mode'], ['value' => 'display']);

    $this->getJson(route('shipping.estimate'))->assertServiceUnavailable();
    $this->post(route('shipping.destination'), [
        'destination_area_id' => 'AREA-1',
        'destination_area_name' => 'Bandung',
    ])->assertRedirect(route('checkout.closed'));
});

test('a picked destination remembers the area parts so checkout can fill them', function () {
    $this->post(route('shipping.destination'), [
        'destination_area_id' => 'AREA-ABE',
        'destination_area_name' => 'Abepura, Jayapura, Papua. 99351',
        'postal_code' => '99351',
        'district' => 'Abepura',
        'city' => 'Jayapura',
        'province' => 'Papua',
    ])->assertRedirect();

    $this->get(route('products.index'))->assertInertia(fn ($page) => $page
        ->where('shippingDestination.city', 'Jayapura')
        ->where('shippingDestination.province', 'Papua')
        ->where('shippingDestination.postal_code', '99351'));
});

test('a destination picked without area parts still works, with them empty', function () {
    $this->post(route('shipping.destination'), [
        'destination_area_id' => 'AREA-1',
        'destination_area_name' => 'Bandung',
    ]);

    $this->get(route('products.index'))->assertInertia(fn ($page) => $page
        ->where('shippingDestination.id', 'AREA-1')
        ->where('shippingDestination.city', null)
        ->where('shippingDestination.province', null));
});
