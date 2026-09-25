<?php

use App\Mail\StaffLowStockMail;
use App\Models\Order;
use App\Models\Product;
use App\Models\Setting;
use App\Models\StockMovement;
use App\Models\User;
use App\Service\Order\OrderStockReleaser;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Mail;
use Spatie\Permission\Models\Permission;

beforeEach(fn () => fakeDefaultPaymentGateway());

function ledgerStaff(array $permissions = ['product.create', 'product.update', 'order.view'], string $email = 'gudang@example.com'): User
{
    $user = User::factory()->create(['email' => $email]);

    foreach ($permissions as $permission) {
        Permission::firstOrCreate(['name' => $permission, 'guard_name' => 'web']);
        $user->givePermissionTo($permission);
    }

    return $user;
}

function ledgerProduct(int $stock = 10, string $name = 'Kopi Wamena'): Product
{
    return Product::create(['name' => $name, 'price' => 60000, 'stock' => $stock, 'weight_gram' => 250, 'is_active' => true]);
}

function ledgerProductPayload(Product $product, array $overrides = []): array
{
    return array_merge([
        'name' => $product->name,
        'price' => $product->price,
        'stock' => $product->stock,
        'stock_seen' => $product->stock,
        'weight_gram' => $product->weight_gram,
        'is_active' => true,
    ], $overrides);
}

function ledgerCheckout(Product $product, int $quantity): Order
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
                'courier_code' => 'jne', 'courier_name' => 'JNE',
                'courier_service_code' => 'reg', 'courier_service_name' => 'Regular',
                'price' => 15000, 'duration' => '2-3 days', 'available_collection_method' => ['pickup'],
            ]],
        ], 200),
    ]);

    test()->post(route('cart.store'), ['product_id' => $product->id, 'quantity' => $quantity]);
    test()->post(route('checkout.store'), [
        'customer_name' => 'Yohana Wenda', 'customer_email' => 'yohana@example.com', 'customer_phone' => '081234567890',
        'shipping_address' => 'Jl. Sentani No. 9', 'shipping_city' => 'Jayapura', 'shipping_province' => 'Papua',
        'shipping_postal_code' => '99111', 'destination_area_id' => 'AREA-1', 'destination_area_name' => 'Jayapura',
        'courier_code' => 'jne', 'courier_service_code' => 'reg', 'notes' => null,
    ])->assertSessionHasNoErrors();

    return Order::latest('id')->firstOrFail();
}

// --- Every stock change leaves a line in the ledger -----------------------

test('a checkout records what it took, against the order', function () {
    $product = ledgerProduct(10);

    $order = ledgerCheckout($product, 3);

    $movement = StockMovement::sole();
    expect($movement->only('product_id', 'order_id', 'change', 'stock_after', 'reason'))->toBe([
        'product_id' => $product->id,
        'order_id' => $order->id,
        'change' => -3,
        'stock_after' => 7,
        'reason' => StockMovement::REASON_CHECKOUT,
    ]);
    expect($movement->user_id)->toBeNull();
});

test('releasing an order records the stock coming back', function () {
    $product = ledgerProduct(10);
    $order = ledgerCheckout($product, 3);

    app(OrderStockReleaser::class)->release($order);

    $movement = StockMovement::where('reason', StockMovement::REASON_RELEASE)->sole();
    expect($movement->change)->toBe(3);
    expect($movement->stock_after)->toBe(10);
    expect($movement->order_id)->toBe($order->id);
});

test('creating a product with stock records the opening count and who entered it', function () {
    $staff = ledgerStaff();

    $this->actingAs($staff)->post(route('backoffice.product.store'), [
        'name' => 'Noken', 'price' => 150000, 'stock' => 12, 'weight_gram' => 300, 'is_active' => true,
    ])->assertSessionHasNoErrors();

    $movement = StockMovement::sole();
    expect($movement->only('change', 'stock_after', 'reason', 'user_id'))->toBe([
        'change' => 12, 'stock_after' => 12, 'reason' => StockMovement::REASON_INITIAL, 'user_id' => $staff->id,
    ]);
});

// --- Saving the product form cannot undo sales -----------------------------

test('a stock edit applies the difference staff made, so sales since the form opened are kept', function () {
    $staff = ledgerStaff();
    $product = ledgerProduct(10);
    $payload = ledgerProductPayload($product, ['stock' => 12]);

    // Three sell while the form is open.
    ledgerCheckout($product, 3);

    $this->actingAs($staff)->put(route('backoffice.product.update', $product->id), $payload)->assertSessionHasNoErrors();

    expect($product->fresh()->stock)->toBe(9);
    $movement = StockMovement::where('reason', StockMovement::REASON_ADJUSTMENT)->sole();
    expect($movement->only('change', 'stock_after', 'user_id'))->toBe(['change' => 2, 'stock_after' => 9, 'user_id' => $staff->id]);
});

test('saving other fields leaves stock exactly as sales left it', function () {
    $staff = ledgerStaff();
    $product = ledgerProduct(10);
    $payload = ledgerProductPayload($product, ['price' => 65000]);

    ledgerCheckout($product, 3);

    $this->actingAs($staff)->put(route('backoffice.product.update', $product->id), $payload)->assertSessionHasNoErrors();

    $product->refresh();
    expect($product->stock)->toBe(7);
    expect($product->price)->toBe(65000);
    expect(StockMovement::where('reason', StockMovement::REASON_ADJUSTMENT)->count())->toBe(0);
});

test('a reduction that would take stock below zero is refused and nothing is saved', function () {
    $staff = ledgerStaff();
    $product = ledgerProduct(10);
    $payload = ledgerProductPayload($product, ['stock' => 0, 'price' => 65000]);

    ledgerCheckout($product, 3);

    $this->actingAs($staff)->put(route('backoffice.product.update', $product->id), $payload)
        ->assertSessionHasErrors('errors');

    $product->refresh();
    expect($product->stock)->toBe(7);
    expect($product->price)->toBe(60000);
});

test('a stock edit must say what count it started from', function () {
    $staff = ledgerStaff();
    $product = ledgerProduct(10);
    $payload = ledgerProductPayload($product);
    unset($payload['stock_seen']);

    $this->actingAs($staff)->put(route('backoffice.product.update', $product->id), $payload)
        ->assertSessionHasErrors('stock_seen');
});

test('turning a product into a bundle records its stock leaving', function () {
    $staff = ledgerStaff();
    $component = ledgerProduct(20, 'Biji Kopi');
    $product = ledgerProduct(5, 'Paket Kopi');

    $this->actingAs($staff)->put(route('backoffice.product.update', $product->id), [
        'name' => 'Paket Kopi', 'price' => 100000, 'is_active' => true, 'is_bundle' => true,
        'bundle_items' => [['product_id' => $component->id, 'quantity' => 2]],
    ])->assertSessionHasNoErrors();

    expect($product->fresh()->stock)->toBe(0);
    $movement = StockMovement::where('product_id', $product->id)->sole();
    expect($movement->change)->toBe(-5);
    expect($movement->stock_after)->toBe(0);
});

test('the product form shows its recent stock history', function () {
    $staff = ledgerStaff();
    $product = ledgerProduct(10);
    ledgerCheckout($product, 3);

    $this->actingAs($staff)->get(route('backoffice.product.show', $product->id))
        ->assertInertia(fn ($page) => $page
            ->where('stockMovements.0.change', -3)
            ->where('stockMovements.0.reason', StockMovement::REASON_CHECKOUT)
            ->has('stockMovements.0.order_number'));
});

// --- Staff hear when a product runs low -----------------------------------

test('stock dropping to the low-stock threshold emails staff once', function () {
    Mail::fake();
    ledgerStaff(['order.view'], 'gudang@example.com');
    Setting::updateOrCreate(['key' => 'low_stock_threshold'], ['value' => '5']);
    $product = ledgerProduct(8);

    ledgerCheckout($product, 2); // 8 -> 6, still above
    Mail::assertNotSent(StaffLowStockMail::class);

    ledgerCheckout($product, 1); // 6 -> 5, crosses
    ledgerCheckout($product, 1); // 5 -> 4, already low

    Mail::assertSent(StaffLowStockMail::class, 1);
    Mail::assertSent(StaffLowStockMail::class, function (StaffLowStockMail $mail) use ($product) {
        expect($mail->render())->toContain($product->name);

        return $mail->hasTo('gudang@example.com');
    });
});

test('a manual reduction that crosses the threshold emails staff too', function () {
    Mail::fake();
    $staff = ledgerStaff(['product.update', 'order.view']);
    $product = ledgerProduct(12);

    $this->actingAs($staff)->put(route('backoffice.product.update', $product->id), ledgerProductPayload($product, ['stock' => 9]))
        ->assertSessionHasNoErrors();

    Mail::assertSent(StaffLowStockMail::class, 1);
});

test('restocking never raises a low-stock email', function () {
    Mail::fake();
    $staff = ledgerStaff(['product.update', 'order.view']);
    $product = ledgerProduct(2);

    $this->actingAs($staff)->put(route('backoffice.product.update', $product->id), ledgerProductPayload($product, ['stock' => 4]))
        ->assertSessionHasNoErrors();

    Mail::assertNotSent(StaffLowStockMail::class);
});
