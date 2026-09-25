<?php

use App\Models\Banner;
use App\Models\Product;
use App\Models\User;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Spatie\Permission\Models\Permission;

function mediaAdmin(array $permissions): User
{
    $user = User::factory()->create();

    foreach ($permissions as $permission) {
        Permission::firstOrCreate(['name' => $permission, 'guard_name' => 'web']);
        $user->givePermissionTo($permission);
    }

    return $user;
}

function productPayload(array $overrides = []): array
{
    return array_merge([
        'name' => 'Kopi Wamena',
        'price' => 60000,
        'stock' => 10,
        'stock_seen' => 10,
        'weight_gram' => 250,
        'is_active' => true,
    ], $overrides);
}

function bannerPayload(array $overrides = []): array
{
    return array_merge([
        'title' => 'Promo Natal',
        'is_active' => true,
        'sort_order' => 1,
    ], $overrides);
}

test('a product can be created without uploading any image', function () {
    $user = mediaAdmin(['product.create']);

    $this->actingAs($user)
        ->post(route('backoffice.product.store'), productPayload())
        ->assertSessionHasNoErrors();

    $product = Product::where('name', 'Kopi Wamena')->first();

    expect($product)->not->toBeNull();
    expect($product->getMedia('images'))->toHaveCount(0);
});

test('a product can be edited without re-uploading its image', function () {
    Storage::fake('public');

    $user = mediaAdmin(['product.create', 'product.update']);

    // Create it with an image, the way the form does on first save.
    $this->actingAs($user)->post(route('backoffice.product.store'), productPayload([
        'images' => [UploadedFile::fake()->image('kopi.jpg')],
    ]));

    $product = Product::where('name', 'Kopi Wamena')->first();
    expect($product->getMedia('images'))->toHaveCount(1);

    // Now change only the price, sending no file at all.
    $this->actingAs($user)
        ->put(route('backoffice.product.update', $product->id), productPayload([
            'price' => 75000,
        ]))
        ->assertSessionHasNoErrors();

    $product->refresh();

    expect($product->price)->toBe(75000);
    // The existing image must survive an edit that did not touch it.
    expect($product->getMedia('images'))->toHaveCount(1);
});

test('uploading an image on edit adds it to the product', function () {
    Storage::fake('public');

    $user = mediaAdmin(['product.create', 'product.update']);

    $this->actingAs($user)->post(route('backoffice.product.store'), productPayload());
    $product = Product::where('name', 'Kopi Wamena')->first();
    expect($product->getMedia('images'))->toHaveCount(0);

    $this->actingAs($user)
        ->put(route('backoffice.product.update', $product->id), productPayload([
            'images' => [UploadedFile::fake()->image('kopi.jpg')],
        ]))
        ->assertSessionHasNoErrors();

    expect($product->fresh()->getMedia('images'))->toHaveCount(1);
});

test('a bundle can be edited without re-uploading its image', function () {
    Storage::fake('public');

    $user = mediaAdmin(['product.create', 'product.update']);

    $coffee = Product::create(productPayload(['name' => 'Kopi Komponen']));
    $noken = Product::create(productPayload(['name' => 'Noken Komponen', 'stock' => 4]));

    $this->actingAs($user)->post(route('backoffice.product.store'), [
        'name' => 'Paket Oleh-oleh',
        'price' => 200000,
        'is_active' => true,
        'is_bundle' => true,
        'images' => [UploadedFile::fake()->image('paket.jpg')],
        'bundle_items' => [['product_id' => $coffee->id, 'quantity' => 2]],
    ]);

    $bundle = Product::where('name', 'Paket Oleh-oleh')->first();
    expect($bundle)->not->toBeNull();

    // Change what is inside the bundle, sending no file.
    $this->actingAs($user)
        ->put(route('backoffice.product.update', $bundle->id), [
            'name' => 'Paket Oleh-oleh',
            'price' => 210000,
            'is_active' => true,
            'is_bundle' => true,
            'bundle_items' => [
                ['product_id' => $coffee->id, 'quantity' => 1],
                ['product_id' => $noken->id, 'quantity' => 1],
            ],
        ])
        ->assertSessionHasNoErrors();

    $bundle = $bundle->fresh('bundleItems.product');

    expect($bundle->price)->toBe(210000);
    expect($bundle->bundleItems)->toHaveCount(2);
    expect($bundle->getMedia('images'))->toHaveCount(1);
    expect($bundle->availableStock())->toBe(4);
});

test('a banner can be created and edited without uploading an image', function () {
    Storage::fake('public');

    $user = mediaAdmin(['banner.create', 'banner.update']);

    $this->actingAs($user)
        ->post(route('backoffice.banner.store'), bannerPayload())
        ->assertSessionHasNoErrors();

    $banner = Banner::where('title', 'Promo Natal')->first();

    expect($banner)->not->toBeNull();
    expect($banner->getMedia('image'))->toHaveCount(0);

    $this->actingAs($user)
        ->put(route('backoffice.banner.update', $banner->id), bannerPayload([
            'title' => 'Promo Tahun Baru',
        ]))
        ->assertSessionHasNoErrors();

    expect($banner->fresh()->title)->toBe('Promo Tahun Baru');
});

test('uploading an image on banner edit attaches it', function () {
    Storage::fake('public');

    $user = mediaAdmin(['banner.create', 'banner.update']);

    $this->actingAs($user)->post(route('backoffice.banner.store'), bannerPayload());
    $banner = Banner::where('title', 'Promo Natal')->first();

    $this->actingAs($user)
        ->put(route('backoffice.banner.update', $banner->id), bannerPayload([
            'image' => UploadedFile::fake()->image('promo.jpg'),
        ]))
        ->assertSessionHasNoErrors();

    expect($banner->fresh()->getMedia('image'))->toHaveCount(1);
});

test('several images upload in one save', function () {
    Storage::fake('public');

    $user = mediaAdmin(['product.create']);

    $this->actingAs($user)
        ->post(route('backoffice.product.store'), productPayload([
            'images' => [
                UploadedFile::fake()->image('a.jpg'),
                UploadedFile::fake()->image('b.jpg'),
                UploadedFile::fake()->image('c.jpg'),
            ],
        ]))
        ->assertSessionHasNoErrors();

    expect(Product::where('name', 'Kopi Wamena')->first()->getMedia('images'))->toHaveCount(3);
});
