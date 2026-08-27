<?php

use App\Models\Product;
use Illuminate\Support\Facades\Http;

function biteshipStorefrontConfig(): void
{
    config([
        'services.biteship.api_key' => 'biteship_test.abc',
        'services.biteship.base_url' => 'https://api.biteship.com',
        'services.biteship.origin_area_id' => 'IDNP6IDNC148IDND854IDZ10730',
        'services.biteship.couriers' => 'jne,jnt',
    ]);
}

test('searchAreas returns an empty list for queries shorter than 3 characters', function () {
    biteshipStorefrontConfig();

    $response = $this->get(route('checkout.shipping-areas', ['q' => 'ja']));

    $response->assertOk();
    $response->assertJson(['areas' => []]);
});

test('searchAreas proxies to Biteship for a valid query', function () {
    biteshipStorefrontConfig();

    Http::fake([
        'api.biteship.com/v1/maps/areas*' => Http::response([
            'success' => true,
            'areas' => [
                ['id' => 'IDNP1IDNC1IDND1IDZ10110', 'name' => 'Jayapura, Papua', 'postal_code' => '99111'],
            ],
        ], 200),
    ]);

    $response = $this->get(route('checkout.shipping-areas', ['q' => 'Jayapura']));

    $response->assertOk();
    $response->assertJson([
        'areas' => [
            ['id' => 'IDNP1IDNC1IDND1IDZ10110', 'name' => 'Jayapura, Papua', 'postal_code' => '99111'],
        ],
    ]);
});

test('rates rejects an empty cart', function () {
    biteshipStorefrontConfig();

    $response = $this->postJson(route('checkout.shipping-rates'), [
        'destination_area_id' => 'IDNP1IDNC1IDND1IDZ10110',
    ]);

    $response->assertStatus(422);
});

test('rates computes total weight from the cart and quotes against it', function () {
    biteshipStorefrontConfig();

    $heavy = Product::create(['name' => 'Karung Beras', 'price' => 200000, 'stock' => 10, 'weight_gram' => 5000, 'is_active' => true]);
    $light = Product::create(['name' => 'Kopi Sachet', 'price' => 10000, 'stock' => 10, 'weight_gram' => 100, 'is_active' => true]);

    $this->post(route('cart.store'), ['product_id' => $heavy->id, 'quantity' => 2]);
    $this->post(route('cart.store'), ['product_id' => $light->id, 'quantity' => 3]);

    Http::fake([
        'api.biteship.com/v1/rates/couriers' => Http::response([
            'success' => true,
            'pricing' => [
                [
                    'courier_code' => 'jne',
                    'courier_name' => 'JNE',
                    'courier_service_code' => 'reg',
                    'courier_service_name' => 'Regular',
                    'price' => 25000,
                    'duration' => '2-3 days',
                    'available_collection_method' => ['pickup', 'drop_off'],
                ],
            ],
        ], 200),
    ]);

    $response = $this->postJson(route('checkout.shipping-rates'), [
        'destination_area_id' => 'IDNP1IDNC1IDND1IDZ10110',
    ]);

    $response->assertOk();
    $response->assertJsonCount(1, 'rates');
    $response->assertJsonPath('rates.0.price', 25000);

    // 2 * 5000 + 3 * 100 = 10300 grams; subtotal = 2*200000 + 3*10000 = 430000.
    Http::assertSent(function ($request) {
        if (! str_contains($request->url(), '/v1/rates/couriers')) {
            return false;
        }

        expect($request['items'][0]['weight'])->toBe(10300);
        expect($request['items'][0]['value'])->toBe(430000);

        return true;
    });
});
