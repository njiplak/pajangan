<?php

use App\Models\Producer;
use App\Models\Product;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Spatie\Permission\Models\Permission;

function catalogueStaff(array $permissions): User
{
    $user = User::factory()->create();
    foreach ($permissions as $permission) {
        Permission::firstOrCreate(['name' => $permission, 'guard_name' => 'web']);
        $user->givePermissionTo($permission);
    }

    return $user;
}

function catalogueProduct(array $overrides = []): Product
{
    return Product::create(array_merge([
        'name' => 'Kopi '.uniqid(), 'price' => 60000, 'stock' => 10, 'weight_gram' => 250, 'is_active' => true,
    ], $overrides));
}

test('the migration promotes existing producer text into linked producers', function () {
    $migration = require database_path('migrations/2026_09_24_050000_create_producers_table.php');
    $migration->down();

    // Legacy rows: free text only. Same name in two regions is two producers.
    foreach ([['Koperasi Wamena', 'Wamena'], ['Koperasi Wamena', 'Wamena'], ['Koperasi Wamena', 'Jayapura'], [null, null]] as $i => [$name, $region]) {
        DB::table('products')->insert([
            'name' => "P{$i}", 'slug' => "p{$i}", 'price' => 1, 'stock' => 1, 'weight_gram' => 1,
            'is_active' => true, 'producer_name' => $name, 'producer_region' => $region,
            'created_at' => now(), 'updated_at' => now(),
        ]);
    }

    $migration->up();

    expect(DB::table('producers')->count())->toBe(2);
    expect(DB::table('producers')->pluck('slug')->sort()->values()->all())->toBe(['koperasi-wamena', 'koperasi-wamena-2']);
    expect(DB::table('products')->whereNotNull('producer_name')->whereNull('producer_id')->count())->toBe(0);
    expect(DB::table('products')->where('slug', 'p0')->value('producer_id'))
        ->toBe(DB::table('products')->where('slug', 'p1')->value('producer_id'));
    expect(DB::table('products')->where('slug', 'p3')->value('producer_id'))->toBeNull();
});

test('a product takes its producer name and region from the producer', function () {
    $staff = catalogueStaff(['product.create', 'product.update']);
    $wamena = Producer::create(['name' => 'Koperasi Wamena', 'region' => 'Wamena']);
    $arfak = Producer::create(['name' => 'Tani Arfak', 'region' => 'Manokwari']);

    $this->actingAs($staff)->post(route('backoffice.product.store'), [
        'name' => 'Kopi', 'price' => 60000, 'stock' => 5, 'weight_gram' => 250, 'is_active' => true,
        'producer_id' => $wamena->id,
        // A stale value sent by an old form must not win over the producer.
        'producer_name' => 'Nama Palsu',
    ]);
    $product = Product::where('name', 'Kopi')->first();
    expect($product->only('producer_id', 'producer_name', 'producer_region'))
        ->toBe(['producer_id' => $wamena->id, 'producer_name' => 'Koperasi Wamena', 'producer_region' => 'Wamena']);

    $this->put(route('backoffice.product.update', $product->id), [
        'name' => 'Kopi', 'price' => 60000, 'stock' => 5, 'weight_gram' => 250, 'is_active' => true,
        'producer_id' => $arfak->id,
    ]);
    expect($product->fresh()->producer_name)->toBe('Tani Arfak');

    $this->put(route('backoffice.product.update', $product->id), [
        'name' => 'Kopi', 'price' => 60000, 'stock' => 5, 'weight_gram' => 250, 'is_active' => true,
        'producer_id' => null,
    ]);
    expect($product->fresh()->only('producer_id', 'producer_name'))->toBe(['producer_id' => null, 'producer_name' => null]);
});

test('renaming a producer updates every product that names it', function () {
    $staff = catalogueStaff(['product.update']);
    $producer = Producer::create(['name' => 'Nama Lama', 'region' => 'Wamena']);
    $product = catalogueProduct(['producer_id' => $producer->id, 'producer_name' => 'Nama Lama', 'producer_region' => 'Wamena']);

    $this->actingAs($staff)->put(route('backoffice.producer.update', $producer->id), [
        'name' => 'Nama Baru', 'region' => 'Wamena, Papua Pegunungan', 'is_active' => true,
    ])->assertSessionHasNoErrors();

    expect($product->fresh()->only('producer_name', 'producer_region'))
        ->toBe(['producer_name' => 'Nama Baru', 'producer_region' => 'Wamena, Papua Pegunungan']);
});

test('deleting a producer leaves no product naming it', function () {
    $staff = catalogueStaff(['product.delete']);
    $producer = Producer::create(['name' => 'Koperasi Wamena']);
    $product = catalogueProduct(['producer_id' => $producer->id, 'producer_name' => 'Koperasi Wamena']);

    $this->actingAs($staff)->delete(route('backoffice.producer.destroy', $producer->id));

    expect(Producer::count())->toBe(0);
    expect($product->fresh()->only('producer_id', 'producer_name'))->toBe(['producer_id' => null, 'producer_name' => null]);
});

test('a producer can be created without a photo', function () {
    $this->actingAs(catalogueStaff(['product.create']))
        ->post(route('backoffice.producer.store'), ['name' => 'Sanggar Tenun', 'region' => 'Mimika', 'is_active' => true])
        ->assertSessionHasNoErrors();

    expect(Producer::where('name', 'Sanggar Tenun')->value('slug'))->toBe('sanggar-tenun');
});

test('managing producers needs the product permissions', function () {
    $this->actingAs(catalogueStaff([]))->post(route('backoffice.producer.store'), ['name' => 'X'])->assertForbidden();
    expect(Producer::count())->toBe(0);
});

test('the producer directory lists active producers with their live product count', function () {
    $wamena = Producer::create(['name' => 'Koperasi Wamena', 'region' => 'Wamena']);
    Producer::create(['name' => 'Tersembunyi', 'is_active' => false]);
    catalogueProduct(['producer_id' => $wamena->id]);
    catalogueProduct(['producer_id' => $wamena->id]);
    catalogueProduct(['producer_id' => $wamena->id, 'is_active' => false]);

    $this->get(route('producers.index'))->assertInertia(fn ($page) => $page
        ->has('producers', 1)
        ->where('producers.0.name', 'Koperasi Wamena')
        ->where('producers.0.product_count', 2));
});

test('a producer page shows the producer and only their products on sale', function () {
    $wamena = Producer::create(['name' => 'Koperasi Wamena', 'story' => 'Petani kopi di lembah Baliem.']);
    $mine = catalogueProduct(['name' => 'Kopi Baliem', 'producer_id' => $wamena->id]);
    catalogueProduct(['name' => 'Bukan Punyanya']);
    catalogueProduct(['name' => 'Ditarik', 'producer_id' => $wamena->id, 'is_active' => false]);

    $this->get(route('producers.show', $wamena->slug))->assertInertia(fn ($page) => $page
        ->where('producer.story', 'Petani kopi di lembah Baliem.')
        ->has('products', 1)
        ->where('products.0.name', 'Kopi Baliem'));
});

test('an inactive producer has no public page and no product link', function () {
    $hidden = Producer::create(['name' => 'Tersembunyi', 'is_active' => false]);
    $product = catalogueProduct(['producer_id' => $hidden->id, 'producer_name' => 'Tersembunyi']);

    $this->get(route('producers.show', $hidden->slug))->assertNotFound();
    $this->get(route('products.show', $product->slug))->assertInertia(fn ($page) => $page
        ->where('product.producer_slug', null)
        ->where('product.producer_name', 'Tersembunyi'));
});

test('a product links to its producer page', function () {
    $wamena = Producer::create(['name' => 'Koperasi Wamena']);
    $product = catalogueProduct(['producer_id' => $wamena->id, 'producer_name' => 'Koperasi Wamena']);

    $this->get(route('products.show', $product->slug))
        ->assertInertia(fn ($page) => $page->where('product.producer_slug', 'koperasi-wamena'));
});
