<?php

use App\Models\Category;
use App\Models\Product;
use App\Models\User;
use Spatie\Permission\Models\Permission;

function categoryStaff(array $permissions): User
{
    $user = User::factory()->create();
    foreach ($permissions as $permission) {
        Permission::firstOrCreate(['name' => $permission, 'guard_name' => 'web']);
        $user->givePermissionTo($permission);
    }

    return $user;
}

function categorised(string $name, ?Category $category, bool $active = true): Product
{
    return Product::create([
        'name' => $name, 'price' => 60000, 'stock' => 10, 'weight_gram' => 250,
        'is_active' => $active, 'category_id' => $category?->id,
    ]);
}

test('the listing filters by category', function () {
    $kopi = Category::create(['name' => 'Kopi']);
    $noken = Category::create(['name' => 'Noken']);
    categorised('Kopi Wamena', $kopi);
    categorised('Noken Anggrek', $noken);

    $this->get(route('products.index', ['kategori' => 'kopi']))->assertInertia(fn ($page) => $page
        ->where('activeCategory', 'kopi')
        ->has('products.data', 1)
        ->where('products.data.0.name', 'Kopi Wamena'));
});

test('category and search combine', function () {
    $kopi = Category::create(['name' => 'Kopi']);
    categorised('Kopi Wamena', $kopi);
    categorised('Kopi Moanemani', $kopi);
    categorised('Wamena Madu', null);

    $this->get(route('products.index', ['kategori' => 'kopi', 'q' => 'Wamena']))->assertInertia(fn ($page) => $page
        ->has('products.data', 1)
        ->where('products.data.0.name', 'Kopi Wamena'));
});

test('an unknown category shows everything instead of an empty page', function () {
    categorised('Kopi Wamena', Category::create(['name' => 'Kopi']));
    categorised('Madu', null);

    $this->get(route('products.index', ['kategori' => 'dihapus']))->assertInertia(fn ($page) => $page
        ->where('activeCategory', null)
        ->has('products.data', 2));
});

test('only categories with products on sale are offered as chips, in order', function () {
    $second = Category::create(['name' => 'Madu', 'sort_order' => 2]);
    $first = Category::create(['name' => 'Kopi', 'sort_order' => 1]);
    $empty = Category::create(['name' => 'Kosong']);
    $retired = Category::create(['name' => 'Ditarik']);
    categorised('A', $second);
    categorised('B', $first);
    categorised('C', $retired, active: false);

    $this->get(route('products.index'))->assertInertia(fn ($page) => $page
        ->has('categories', 2)
        ->where('categories.0.slug', 'kopi')
        ->where('categories.1.slug', 'madu'));
});

test('deleting a category keeps its products, uncategorised', function () {
    $kopi = Category::create(['name' => 'Kopi']);
    $product = categorised('Kopi Wamena', $kopi);

    $this->actingAs(categoryStaff(['product.delete']))->delete(route('backoffice.category.destroy', $kopi->id));

    expect(Category::count())->toBe(0);
    expect($product->fresh())->not->toBeNull();
    expect($product->fresh()->category_id)->toBeNull();
});

test('staff assign a category on the product form', function () {
    $kopi = Category::create(['name' => 'Kopi']);

    $this->actingAs(categoryStaff(['product.create']))->post(route('backoffice.product.store'), [
        'name' => 'Kopi Wamena', 'price' => 60000, 'stock' => 5, 'weight_gram' => 250,
        'is_active' => true, 'category_id' => $kopi->id,
    ])->assertSessionHasNoErrors();

    expect(Product::where('name', 'Kopi Wamena')->value('category_id'))->toBe($kopi->id);
});

test('category slugs stay unique', function () {
    $staff = categoryStaff(['product.create']);

    $this->actingAs($staff)->post(route('backoffice.category.store'), ['name' => 'Kerajinan']);
    $this->post(route('backoffice.category.store'), ['name' => 'Kerajinan']);

    expect(Category::pluck('slug')->sort()->values()->all())->toBe(['kerajinan', 'kerajinan-2']);
});

test('managing categories needs the product permissions', function () {
    $this->actingAs(categoryStaff([]))->post(route('backoffice.category.store'), ['name' => 'X'])->assertForbidden();
});
