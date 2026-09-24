<?php

use App\Service\Shipping\BiteshipService;
use Illuminate\Support\Facades\Http;

function biteshipConfig(): void
{
    config([
        'services.biteship.api_key' => 'biteship_test.abc',
        'services.biteship.base_url' => 'https://api.biteship.com',
        'services.biteship.origin_area_id' => 'IDNP6IDNC148IDND854IDZ10730',
        'services.biteship.couriers' => 'jne,jnt',
    ]);
}

test('isConfigured requires both an api key and an origin area id', function () {
    config(['services.biteship.api_key' => null, 'services.biteship.origin_area_id' => null]);
    expect(app(BiteshipService::class)->isConfigured())->toBeFalse();

    biteshipConfig();
    expect(app(BiteshipService::class)->isConfigured())->toBeTrue();
});

test('searchAreas calls the maps endpoint with the auth header and maps the response', function () {
    biteshipConfig();

    Http::fake([
        'api.biteship.com/v1/maps/areas*' => Http::response([
            'success' => true,
            'areas' => [
                ['id' => 'IDNP6IDNC148IDND854IDZ10730', 'name' => 'Jayapura, Papua', 'postal_code' => '99111'],
            ],
        ], 200),
    ]);

    $areas = app(BiteshipService::class)->searchAreas('Jayapura');

    // This minimal payload carries no division names; they map to null so
    // the storefront falls back to letting the customer type them.
    expect($areas)->toBe([
        [
            'id' => 'IDNP6IDNC148IDND854IDZ10730',
            'name' => 'Jayapura, Papua',
            'postal_code' => '99111',
            'district' => null,
            'city' => null,
            'province' => null,
        ],
    ]);

    Http::assertSent(function ($request) {
        expect($request->url())->toContain('/v1/maps/areas');
        expect($request['input'])->toBe('Jayapura');
        expect($request['countries'])->toBe('ID');
        expect($request->hasHeader('authorization', 'biteship_test.abc'))->toBeTrue();

        return true;
    });
});

test('searchAreas maps the administrative divisions when Biteship supplies them', function () {
    biteshipConfig();

    // Shape per Biteship's Maps documentation; not yet captured from a
    // live call (the API is unreachable from the build sandbox). Replace
    // with a recorded response once one is available.
    Http::fake([
        'api.biteship.com/v1/maps/areas*' => Http::response([
            'success' => true,
            'areas' => [[
                'id' => 'IDNP1IDNC1IDND1IDZ99351',
                'name' => 'Abepura, Jayapura, Papua. 99351',
                'country_name' => 'Indonesia',
                'country_code' => 'ID',
                'administrative_division_level_1_name' => 'Papua',
                'administrative_division_level_1_type' => 'province',
                'administrative_division_level_2_name' => 'Jayapura',
                'administrative_division_level_2_type' => 'city',
                'administrative_division_level_3_name' => 'Abepura',
                'administrative_division_level_3_type' => 'district',
                'postal_code' => 99351,
            ]],
        ], 200),
    ]);

    expect(app(BiteshipService::class)->searchAreas('abepura'))->toBe([[
        'id' => 'IDNP1IDNC1IDND1IDZ99351',
        'name' => 'Abepura, Jayapura, Papua. 99351',
        // Numeric in some responses; always a string here.
        'postal_code' => '99351',
        'district' => 'Abepura',
        'city' => 'Jayapura',
        'province' => 'Papua',
    ]]);
});

test('searchAreas treats blank division names as missing', function () {
    biteshipConfig();

    Http::fake([
        'api.biteship.com/v1/maps/areas*' => Http::response([
            'success' => true,
            'areas' => [[
                'id' => 'X', 'name' => 'X',
                'administrative_division_level_1_name' => '  ',
                'administrative_division_level_2_name' => null,
                'postal_code' => '',
            ]],
        ], 200),
    ]);

    $area = app(BiteshipService::class)->searchAreas('x')[0];

    expect([$area['province'], $area['city'], $area['postal_code']])->toBe([null, null, null]);
});

test('searchAreas throws when the response is not successful', function () {
    biteshipConfig();

    Http::fake([
        'api.biteship.com/v1/maps/areas*' => Http::response(['success' => false, 'error' => 'bad input'], 400),
    ]);

    app(BiteshipService::class)->searchAreas('??');
})->throws(RuntimeException::class, 'Biteship area search failed: bad input');

test('quoteRates posts origin, destination, couriers and item weight, and maps pricing including collection methods', function () {
    biteshipConfig();

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

    $rates = app(BiteshipService::class)->quoteRates([
        'destination_area_id' => 'IDNP1IDNC1IDND1IDZ10110',
        'weight_gram' => 1500,
        'item_value' => 150000,
    ]);

    expect($rates)->toBe([
        [
            'courier_code' => 'jne',
            'courier_name' => 'JNE',
            'courier_service_code' => 'reg',
            'courier_service_name' => 'Regular',
            'price' => 25000,
            'duration' => '2-3 days',
            'collection_methods' => ['pickup', 'drop_off'],
        ],
    ]);

    Http::assertSent(function ($request) {
        expect($request->url())->toContain('/v1/rates/couriers');
        expect($request['origin_area_id'])->toBe('IDNP6IDNC148IDND854IDZ10730');
        expect($request['destination_area_id'])->toBe('IDNP1IDNC1IDND1IDZ10110');
        expect($request['couriers'])->toBe('jne,jnt');
        expect($request['items'][0]['weight'])->toBe(1500);
        expect($request['items'][0]['value'])->toBe(150000);

        return true;
    });
});

test('quoteRates defaults collection_methods to an empty array when Biteship omits it', function () {
    biteshipConfig();

    Http::fake([
        'api.biteship.com/v1/rates/couriers' => Http::response([
            'success' => true,
            'pricing' => [[
                'courier_code' => 'jne',
                'courier_name' => 'JNE',
                'courier_service_code' => 'reg',
                'courier_service_name' => 'Regular',
                'price' => 25000,
                'duration' => null,
            ]],
        ], 200),
    ]);

    $rates = app(BiteshipService::class)->quoteRates([
        'destination_area_id' => 'x',
        'weight_gram' => 1000,
        'item_value' => 10000,
    ]);

    expect($rates[0]['collection_methods'])->toBe([]);
});

test('quoteRates refuses to call the API when not configured', function () {
    config(['services.biteship.api_key' => null, 'services.biteship.origin_area_id' => null]);

    app(BiteshipService::class)->quoteRates([
        'destination_area_id' => 'x',
        'weight_gram' => 1000,
        'item_value' => 10000,
    ]);
})->throws(RuntimeException::class, 'Biteship is not configured.');

function biteshipSenderConfig(): void
{
    biteshipConfig();
    config([
        'services.biteship.sender_name' => 'Toko Pajangan',
        'services.biteship.sender_phone' => '081234567890',
        'services.biteship.sender_address' => 'Jl. Gudang No. 1',
    ]);
}

test('createShipment refuses to call the API when sender identity is not configured', function () {
    biteshipConfig();

    app(BiteshipService::class)->createShipment([
        'destination_area_id' => 'x',
        'destination_contact_name' => 'Budi',
        'destination_contact_phone' => '0812',
        'destination_address' => 'Jl. Test',
        'weight_gram' => 1000,
        'item_value' => 10000,
        'courier_code' => 'jne',
        'courier_service_code' => 'reg',
    ]);
})->throws(RuntimeException::class, 'Biteship sender identity is not configured.');

test('createShipment posts sender, destination, courier and item fields and maps the response', function () {
    biteshipSenderConfig();

    Http::fake([
        'api.biteship.com/v1/orders' => Http::response([
            'success' => true,
            'id' => 'bts-order-1',
            'status' => 'confirmed',
            'price' => 25000,
            'courier' => [
                'company' => 'jne',
                'type' => 'reg',
                'waybill_id' => null,
                'tracking_id' => 'trk-1',
            ],
        ], 200),
    ]);

    $shipment = app(BiteshipService::class)->createShipment([
        'destination_area_id' => 'IDNP1IDNC1IDND1IDZ10110',
        'destination_contact_name' => 'Budi',
        'destination_contact_phone' => '0812',
        'destination_address' => 'Jl. Test',
        'weight_gram' => 1500,
        'item_value' => 150000,
        'courier_code' => 'jne',
        'courier_service_code' => 'reg',
    ]);

    expect($shipment)->toBe([
        'provider_order_id' => 'bts-order-1',
        'status' => 'confirmed',
        'tracking_number' => null,
        'courier_code' => 'jne',
        'courier_name' => 'JNE',
        'courier_service' => 'reg',
        'price' => 25000,
    ]);

    Http::assertSent(function ($request) {
        expect($request->url())->toContain('/v1/orders');
        expect($request['origin_contact_name'])->toBe('Toko Pajangan');
        expect($request['origin_area_id'])->toBe('IDNP6IDNC148IDND854IDZ10730');
        expect($request['destination_contact_name'])->toBe('Budi');
        expect($request['courier_company'])->toBe('jne');
        expect($request['courier_type'])->toBe('reg');
        expect($request['items'][0]['weight'])->toBe(1500);

        return true;
    });
});

test('createShipment throws when Biteship rejects the order', function () {
    biteshipSenderConfig();

    Http::fake([
        'api.biteship.com/v1/orders' => Http::response(['success' => false, 'error' => 'invalid courier'], 400),
    ]);

    app(BiteshipService::class)->createShipment([
        'destination_area_id' => 'x',
        'destination_contact_name' => 'Budi',
        'destination_contact_phone' => '0812',
        'destination_address' => 'Jl. Test',
        'weight_gram' => 1000,
        'item_value' => 10000,
        'courier_code' => 'jne',
        'courier_service_code' => 'reg',
    ]);
})->throws(RuntimeException::class, 'Biteship shipment creation failed: invalid courier');

test('getShipmentStatus fetches and maps the order by id, including a populated waybill', function () {
    biteshipConfig();

    Http::fake([
        'api.biteship.com/v1/orders/bts-order-1' => Http::response([
            'success' => true,
            'id' => 'bts-order-1',
            'status' => 'allocated',
            'price' => 25000,
            'courier' => [
                'company' => 'jne',
                'type' => 'reg',
                'waybill_id' => 'WYB-123',
                'tracking_id' => 'trk-1',
            ],
        ], 200),
    ]);

    $shipment = app(BiteshipService::class)->getShipmentStatus('bts-order-1');

    expect($shipment)->toBe([
        'provider_order_id' => 'bts-order-1',
        'status' => 'allocated',
        'tracking_number' => 'WYB-123',
        'courier_code' => 'jne',
        'courier_name' => 'JNE',
        'courier_service' => 'reg',
        'price' => 25000,
    ]);
});

test('cancelShipment posts the reason code to the cancel endpoint and maps the response', function () {
    biteshipConfig();

    Http::fake([
        'api.biteship.com/v1/orders/bts-order-1/cancel' => Http::response([
            'success' => true,
            'id' => 'bts-order-1',
            'status' => 'cancelled',
        ], 200),
    ]);

    $result = app(BiteshipService::class)->cancelShipment('bts-order-1', 'change_courier');

    expect($result)->toBe(['provider_order_id' => 'bts-order-1', 'status' => 'cancelled']);

    Http::assertSent(function ($request) {
        expect($request->url())->toContain('/v1/orders/bts-order-1/cancel');
        expect($request['cancellation_reason_code'])->toBe('change_courier');
        expect($request->data())->not->toHaveKey('cancellation_reason');

        return true;
    });
});

test('cancelShipment includes a custom reason when given', function () {
    biteshipConfig();

    Http::fake([
        'api.biteship.com/v1/orders/bts-order-1/cancel' => Http::response([
            'success' => true,
            'id' => 'bts-order-1',
            'status' => 'cancelled',
        ], 200),
    ]);

    app(BiteshipService::class)->cancelShipment('bts-order-1', 'others', 'Salah alamat');

    Http::assertSent(function ($request) {
        expect($request['cancellation_reason_code'])->toBe('others');
        expect($request['cancellation_reason'])->toBe('Salah alamat');

        return true;
    });
});

test('cancelShipment throws when Biteship rejects the cancellation', function () {
    biteshipConfig();

    Http::fake([
        'api.biteship.com/v1/orders/bts-order-1/cancel' => Http::response(['success' => false, 'error' => 'already delivered'], 400),
    ]);

    app(BiteshipService::class)->cancelShipment('bts-order-1', 'change_courier');
})->throws(RuntimeException::class, 'Biteship shipment cancellation failed: already delivered');
