<?php

use App\Models\Customer;
use App\Models\CustomerAddress;
use App\Models\Order;
use App\Models\Product;
use App\Service\Customer\AddressBook;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\URL;

function addressData(array $overrides = []): array
{
    return array_merge([
        'label' => 'Rumah',
        'recipient_name' => 'Yohana Wenda',
        'phone' => '081234567890',
        'address' => 'Jl. Sentani No. 9',
        'city' => 'Jayapura',
        'province' => 'Papua',
        'postal_code' => '99111',
        'destination_area_id' => 'AREA-1',
        'destination_area_name' => 'Jayapura, Papua',
    ], $overrides);
}

function shopper(string $email = 'a@example.com'): Customer
{
    return Customer::create(['name' => 'A', 'email' => $email, 'email_verified_at' => now()]);
}

function tier2Product(string $name = 'Kopi Wamena', int $stock = 10, bool $active = true): Product
{
    return Product::create(['name' => $name, 'price' => 60000, 'stock' => $stock, 'weight_gram' => 250, 'is_active' => $active]);
}

// --- Addresses --------------------------------------------------------------

test('the first saved address becomes the default', function () {
    $c = shopper();

    $this->actingAs($c, 'customer')->post(route('account.addresses.store'), addressData())->assertSessionHasNoErrors();

    expect($c->addresses()->first()->is_default)->toBeTrue();
});

test('making another address default leaves exactly one default', function () {
    $c = shopper();
    $book = app(AddressBook::class);
    $home = $book->create($c, addressData(['label' => 'Rumah']));
    $office = $book->create($c, addressData(['label' => 'Kantor']));

    $this->actingAs($c, 'customer')->post(route('account.addresses.default', $office->id));

    expect($home->fresh()->is_default)->toBeFalse();
    expect($office->fresh()->is_default)->toBeTrue();
    expect($c->addresses()->where('is_default', true)->count())->toBe(1);
});

test('deleting the default promotes another so one always remains', function () {
    $c = shopper();
    $book = app(AddressBook::class);
    $home = $book->create($c, addressData(['label' => 'Rumah']));
    $office = $book->create($c, addressData(['label' => 'Kantor']));

    $this->actingAs($c, 'customer')->delete(route('account.addresses.destroy', $home->id));

    expect(CustomerAddress::find($home->id))->toBeNull();
    expect($office->fresh()->is_default)->toBeTrue();
});

test('a customer cannot touch another customer\'s address', function () {
    $mine = shopper('a@example.com');
    $theirs = shopper('b@example.com');
    $address = app(AddressBook::class)->create($theirs, addressData());

    $this->actingAs($mine, 'customer')->put(route('account.addresses.update', $address->id), addressData(['city' => 'Hacked']))->assertNotFound();
    $this->actingAs($mine, 'customer')->delete(route('account.addresses.destroy', $address->id))->assertNotFound();
    $this->actingAs($mine, 'customer')->post(route('account.addresses.default', $address->id))->assertNotFound();

    expect($address->fresh()->city)->toBe('Jayapura');
});

test('an address needs a courier area so checkout can quote shipping', function () {
    $this->actingAs(shopper(), 'customer')
        ->post(route('account.addresses.store'), addressData(['destination_area_id' => '']))
        ->assertSessionHasErrors('destination_area_id');
});

test('the address book is capped', function () {
    $c = shopper();
    $book = app(AddressBook::class);
    foreach (range(1, AddressBook::MAX_ADDRESSES) as $i) {
        $book->create($c, addressData(['label' => "A{$i}"]));
    }

    $this->actingAs($c, 'customer')->post(route('account.addresses.store'), addressData())->assertSessionHasErrors('address');
    expect($c->addresses()->count())->toBe(AddressBook::MAX_ADDRESSES);
});

// --- Checkout ---------------------------------------------------------------

function fakeTier2Rate(): void
{
    config([
        'services.biteship.api_key' => 'biteship_test.abc',
        'services.biteship.base_url' => 'https://api.biteship.com',
        'services.biteship.origin_area_id' => 'ORIGIN-1',
        'services.biteship.couriers' => 'jne',
    ]);
    Http::fake(['api.biteship.com/v1/rates/couriers' => Http::response(['success' => true, 'pricing' => [[
        'courier_code' => 'jne', 'courier_name' => 'JNE', 'courier_service_code' => 'reg',
        'courier_service_name' => 'Regular', 'price' => 15000, 'duration' => '2-3 days',
        'available_collection_method' => ['pickup'],
    ]]], 200)]);
}

function tier2Checkout(array $overrides = []): array
{
    return array_merge([
        'customer_name' => 'Yohana Wenda', 'customer_email' => 'a@example.com', 'customer_phone' => '081234567890',
        'shipping_address' => 'Jl. Sentani No. 9', 'shipping_city' => 'Jayapura', 'shipping_province' => 'Papua',
        'shipping_postal_code' => '99111', 'destination_area_id' => 'AREA-1', 'destination_area_name' => 'Jayapura, Papua',
        'courier_code' => 'jne', 'courier_service_code' => 'reg', 'notes' => null,
    ], $overrides);
}

test('checkout offers a signed-in customer their details and address book', function () {
    $c = shopper();
    $c->update(['phone' => '0812']);
    app(AddressBook::class)->create($c, addressData());
    $product = tier2Product();

    $this->actingAs($c, 'customer')->post(route('cart.store'), ['product_id' => $product->id, 'quantity' => 1]);

    $this->get(route('checkout.index'))->assertInertia(fn ($page) => $page
        ->where('profile.email', 'a@example.com')
        ->where('profile.phone', '0812')
        ->has('savedAddresses', 1)
        ->where('savedAddresses.0.destination_area_id', 'AREA-1')
        ->where('savedAddresses.0.is_default', true));
});

test('a guest checkout gets no profile or addresses', function () {
    $product = tier2Product();
    $this->post(route('cart.store'), ['product_id' => $product->id, 'quantity' => 1]);

    $this->get(route('checkout.index'))->assertInertia(fn ($page) => $page
        ->where('profile', null)
        ->where('savedAddresses', []));
});

test('checking out can save the address, once', function () {
    fakeTier2Rate();
    $c = shopper();

    foreach ([1, 2] as $_) {
        $product = tier2Product("Kopi {$_}");
        $this->actingAs($c, 'customer')->post(route('cart.store'), ['product_id' => $product->id, 'quantity' => 1]);
        $this->post(route('checkout.store'), tier2Checkout(['save_address' => true, 'address_label' => 'Rumah']));
    }

    expect(Order::count())->toBe(2);
    // The same address twice is stored once.
    expect($c->addresses()->count())->toBe(1);
    expect($c->addresses()->first()->label)->toBe('Rumah');
});

test('checkout does not save the address unless asked', function () {
    fakeTier2Rate();
    $c = shopper();
    $product = tier2Product();

    $this->actingAs($c, 'customer')->post(route('cart.store'), ['product_id' => $product->id, 'quantity' => 1]);
    $this->post(route('checkout.store'), tier2Checkout());

    expect($c->addresses()->count())->toBe(0);
});

// --- Reorder ----------------------------------------------------------------

function pastOrder(array $lines): Order
{
    $order = Order::create([
        'order_number' => 'ORD-PAST-'.uniqid(),
        'customer_name' => 'A', 'customer_email' => 'a@example.com', 'customer_phone' => '0812',
        'shipping_address' => 'Jl. Test', 'shipping_city' => 'Jayapura', 'shipping_province' => 'Papua',
        'status' => Order::STATUS_COMPLETED, 'subtotal' => 1000, 'total' => 1000,
    ]);

    foreach ($lines as [$product, $name, $qty]) {
        $order->items()->create([
            'product_id' => $product?->id,
            'product_name' => $name,
            'unit_price' => 60000,
            'quantity' => $qty,
            'subtotal' => 60000 * $qty,
        ]);
    }

    return $order;
}

test('buying again puts a past order back in the cart', function () {
    $coffee = tier2Product('Kopi Wamena', 10);
    $noken = tier2Product('Noken', 10);
    $order = pastOrder([[$coffee, 'Kopi Wamena', 2], [$noken, 'Noken', 1]]);

    $this->post(URL::signedRoute('order.reorder', ['order' => $order->order_number]))
        ->assertRedirect(route('cart.index'))
        ->assertSessionHas('status', 'Produk dari pesanan sebelumnya sudah masuk keranjang.');

    expect(session('cart'))->toBe([$coffee->id => 2, $noken->id => 1]);
});

test('buying again says which products are gone or short', function () {
    $short = tier2Product('Kopi Wamena', 1);
    $hidden = tier2Product('Madu', 10, active: false);
    $order = pastOrder([
        [$short, 'Kopi Wamena', 3],
        [$hidden, 'Madu', 1],
        [null, 'Produk Lama', 1],
    ]);

    $this->post(URL::signedRoute('order.reorder', ['order' => $order->order_number]))
        ->assertSessionHas('status', fn (string $s) => str_contains($s, 'Tidak tersedia lagi: Madu, Produk Lama.')
            && str_contains($s, 'Jumlah disesuaikan dengan stok: Kopi Wamena.'));

    expect(session('cart'))->toBe([$short->id => 1]);
});

test('buying again requires the signed link', function () {
    $order = pastOrder([[tier2Product(), 'Kopi', 1]]);

    $this->post(route('order.reorder', ['order' => $order->order_number]))->assertForbidden();
});

test('a pending order offers paying, not buying again', function () {
    $order = pastOrder([[tier2Product(), 'Kopi', 1]]);
    $order->update(['status' => Order::STATUS_PENDING]);

    $this->get(URL::signedRoute('order.show', ['order' => $order->order_number]))
        ->assertInertia(fn ($page) => $page->where('reorderUrl', null));
});

// --- Wishlist ---------------------------------------------------------------

test('a product can be added to and removed from the wishlist', function () {
    $c = shopper();
    $product = tier2Product();

    $this->actingAs($c, 'customer')->post(route('account.wishlist.toggle', $product->id));
    expect($c->wishlist()->count())->toBe(1);

    $this->get(route('products.show', $product->slug))->assertInertia(fn ($page) => $page->where('inWishlist', true));

    $this->post(route('account.wishlist.toggle', $product->id));
    expect($c->wishlist()->count())->toBe(0);
});

test('the wishlist hides products that were deactivated', function () {
    $c = shopper();
    $kept = tier2Product('Kopi');
    $gone = tier2Product('Madu');
    $c->wishlist()->create(['product_id' => $kept->id]);
    $c->wishlist()->create(['product_id' => $gone->id]);
    $gone->update(['is_active' => false]);

    $this->actingAs($c, 'customer')->get(route('account.wishlist.index'))->assertInertia(fn ($page) => $page
        ->has('products', 1)
        ->where('products.0.name', 'Kopi'));
});

test('a guest cannot use the wishlist', function () {
    $product = tier2Product();

    $this->post(route('account.wishlist.toggle', $product->id))->assertRedirect('/masuk');
});
