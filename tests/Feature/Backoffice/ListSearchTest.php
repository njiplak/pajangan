<?php

use App\Models\Banner;
use App\Models\Category;
use App\Models\Order;
use App\Models\Page;
use App\Models\Producer;
use App\Models\Product;
use App\Models\Setting;
use App\Models\User;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;

function searchStaff(string $permission): User
{
    $user = User::factory()->create(['name' => 'Staff Search', 'email' => 'staff-search@example.com']);
    Permission::firstOrCreate(['name' => $permission, 'guard_name' => 'web']);
    $user->givePermissionTo($permission);

    return $user;
}

function searchOrder(string $number, string $name, array $overrides = []): Order
{
    return Order::create(array_merge([
        'order_number' => $number,
        'customer_name' => $name,
        'customer_email' => strtolower(str_replace(' ', '.', $name)).'@example.com',
        'customer_phone' => '0812',
        'shipping_address' => 'Jl. Test',
        'shipping_city' => 'Jayapura',
        'shipping_province' => 'Papua',
        'status' => Order::STATUS_PENDING,
        'subtotal' => 1000,
        'total' => 1000,
    ], $overrides));
}

/**
 * Every admin list shows a search box; each must answer it with matching
 * rows rather than an "unknown filter" error the table renders as empty.
 */
test('searching an admin list returns the matching rows', function (string $route, string $permission, Closure $seed, string $term, string $field, string $expected, string $excluded) {
    $staff = searchStaff($permission);
    $seed();

    $response = $this->actingAs($staff)
        ->getJson(route($route, ['filter' => ['search' => $term]]))
        ->assertOk()
        ->assertJsonStructure(['items']);

    $values = collect($response->json('items'))->pluck($field);
    expect($values)->toContain($expected);
    expect($values)->not->toContain($excluded);
})->with([
    'orders by customer' => ['backoffice.order.fetch', 'order.view', fn () => [searchOrder('ORD-A', 'Yohana Wenda'), searchOrder('ORD-B', 'Budi Santoso')], 'yohana', 'order_number', 'ORD-A', 'ORD-B'],
    'orders by number' => ['backoffice.order.fetch', 'order.view', fn () => [searchOrder('ORD-A', 'Yohana Wenda'), searchOrder('ORD-B', 'Budi Santoso')], 'ord-b', 'order_number', 'ORD-B', 'ORD-A'],
    'products' => ['backoffice.product.fetch', 'product.view', fn () => [
        Product::create(['name' => 'Kopi Wamena', 'price' => 1, 'stock' => 1, 'is_active' => true]),
        Product::create(['name' => 'Noken Anggrek', 'price' => 1, 'stock' => 1, 'is_active' => true]),
    ], 'wamena', 'name', 'Kopi Wamena', 'Noken Anggrek'],
    'producers' => ['backoffice.producer.fetch', 'product.view', fn () => [
        Producer::create(['name' => 'Mama Papua', 'slug' => 'mama-papua', 'region' => 'Wamena']),
        Producer::create(['name' => 'Kelompok Tani', 'slug' => 'kelompok-tani', 'region' => 'Sentani']),
    ], 'wamena', 'name', 'Mama Papua', 'Kelompok Tani'],
    'categories' => ['backoffice.category.fetch', 'product.view', fn () => [
        Category::create(['name' => 'Kopi', 'slug' => 'kopi']),
        Category::create(['name' => 'Kerajinan', 'slug' => 'kerajinan']),
    ], 'kop', 'name', 'Kopi', 'Kerajinan'],
    'pages' => ['backoffice.page.fetch', 'page.view', fn () => [
        Page::create(['title' => 'Tentang Kami', 'slug' => 'tentang', 'body' => 'x']),
        Page::create(['title' => 'Kebijakan Retur', 'slug' => 'retur', 'body' => 'x']),
    ], 'retur', 'title', 'Kebijakan Retur', 'Tentang Kami'],
    'banners' => ['backoffice.banner.fetch', 'banner.view', fn () => [
        Banner::create(['title' => 'Promo Natal']),
        Banner::create(['title' => 'Kopi Baru']),
    ], 'natal', 'title', 'Promo Natal', 'Kopi Baru'],
    'users' => ['backoffice.setting.user.fetch', 'user.view', fn () => [
        User::factory()->create(['name' => 'Agus Kasir', 'email' => 'agus@example.com']),
        User::factory()->create(['name' => 'Rina Gudang', 'email' => 'rina@example.com']),
    ], 'agus@', 'name', 'Agus Kasir', 'Rina Gudang'],
    'roles' => ['backoffice.setting.role.fetch', 'role.view', fn () => [
        Role::create(['name' => 'gudang', 'guard_name' => 'web']),
        Role::create(['name' => 'kasir', 'guard_name' => 'web']),
    ], 'gud', 'name', 'gudang', 'kasir'],
    'permissions' => ['backoffice.setting.permission.fetch', 'permission.view', fn () => [
        Permission::firstOrCreate(['name' => 'report.view', 'guard_name' => 'web']),
        Permission::firstOrCreate(['name' => 'stock.adjust', 'guard_name' => 'web']),
    ], 'report', 'name', 'report.view', 'stock.adjust'],
    'settings' => ['backoffice.setting.setting.fetch', 'setting.view', fn () => [
        Setting::create(['key' => 'low_stock_threshold', 'value' => '10']),
        Setting::create(['key' => 'storefront_title', 'value' => 'UMKM Papua']),
    ], 'stock', 'key', 'low_stock_threshold', 'storefront_title'],
]);

test('a search term with a comma or LIKE wildcards is matched literally', function () {
    $staff = searchStaff('order.view');
    searchOrder('ORD-A', 'Yohana Wenda', ['customer_email' => 'a%b@example.com']);
    searchOrder('ORD-B', 'Budi Santoso');

    $items = $this->actingAs($staff)
        ->getJson(route('backoffice.order.fetch', ['filter' => ['search' => 'a%b']]))
        ->assertOk()
        ->json('items');

    expect(collect($items)->pluck('order_number')->all())->toBe(['ORD-A']);

    $this->actingAs($staff)
        ->getJson(route('backoffice.order.fetch', ['filter' => ['search' => 'Wenda, Yohana']]))
        ->assertOk()
        ->assertJsonStructure(['items']);
});

test('orders can be filtered by exact status and by date placed', function () {
    $staff = searchStaff('order.view');
    searchOrder('ORD-OLD', 'Lama', ['status' => Order::STATUS_PAID]);
    Order::query()->where('order_number', 'ORD-OLD')->update(['created_at' => now()->subDays(10)]);
    searchOrder('ORD-NEW', 'Baru', ['status' => Order::STATUS_PAID]);
    searchOrder('ORD-PEND', 'Tunggu');

    $byStatus = $this->actingAs($staff)
        ->getJson(route('backoffice.order.fetch', ['filter' => ['status' => 'paid']]))
        ->json('items');
    expect(collect($byStatus)->pluck('order_number')->sort()->values()->all())->toBe(['ORD-NEW', 'ORD-OLD']);

    $byDate = $this->actingAs($staff)
        ->getJson(route('backoffice.order.fetch', ['filter' => [
            'status' => 'paid',
            'created_from' => now()->subDays(2)->toDateString(),
            'created_to' => now()->toDateString(),
        ]]))
        ->json('items');
    expect(collect($byDate)->pluck('order_number')->all())->toBe(['ORD-NEW']);

    // A malformed date is ignored, not a broken list.
    $this->actingAs($staff)
        ->getJson(route('backoffice.order.fetch', ['filter' => ['created_from' => "2026-13-99'"]]))
        ->assertOk()
        ->assertJsonCount(3, 'items');
});
