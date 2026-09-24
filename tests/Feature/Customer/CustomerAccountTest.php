<?php

use App\Models\Customer;
use App\Models\Order;
use App\Models\Product;
use Illuminate\Support\Facades\Http;
use Laravel\Socialite\Facades\Socialite;
use Laravel\Socialite\Two\User as GoogleUser;

beforeEach(fn () => fakeDefaultPaymentGateway());

function enableGoogle(): void
{
    config([
        'services.google.client_id' => 'test-client',
        'services.google.client_secret' => 'test-secret',
    ]);
}

function fakeGoogleUser(string $id = 'g-1', string $email = 'yohana@example.com', bool $verified = true, string $name = 'Yohana Wenda'): void
{
    $user = (new GoogleUser)
        ->setRaw(['sub' => $id, 'email' => $email, 'email_verified' => $verified, 'name' => $name])
        ->map(['id' => $id, 'name' => $name, 'email' => $email, 'avatar' => 'https://example.com/a.png']);

    $provider = Mockery::mock();
    $provider->shouldReceive('user')->andReturn($user);

    Socialite::shouldReceive('driver')->with('google')->andReturn($provider);
}

function guestOrder(string $number, string $email): Order
{
    return Order::create([
        'order_number' => $number,
        'customer_name' => 'Yohana', 'customer_email' => $email, 'customer_phone' => '0812',
        'shipping_address' => 'Jl. Test', 'shipping_city' => 'Jayapura', 'shipping_province' => 'Papua',
        'status' => Order::STATUS_PAID, 'subtotal' => 1000, 'total' => 1000,
    ]);
}

test('the Google button is hidden until credentials are configured', function () {
    config(['services.google.client_id' => null, 'services.google.client_secret' => null]);

    $this->get(route('customer.login'))
        ->assertInertia(fn ($page) => $page->where('googleEnabled', false));

    $this->get(route('customer.google'))->assertRedirect(route('customer.login'));
});

test('signing in with Google creates a customer and opens the account', function () {
    enableGoogle();
    fakeGoogleUser();

    $this->get(route('customer.google.callback'))->assertRedirect(route('account.index'));

    $customer = Customer::first();
    expect($customer->email)->toBe('yohana@example.com');
    expect($customer->google_id)->toBe('g-1');
    expect($customer->email_verified_at)->not->toBeNull();
    $this->assertAuthenticatedAs($customer, 'customer');
});

test('a signed-in customer cannot reach the staff backoffice', function () {
    enableGoogle();
    fakeGoogleUser();

    $this->get(route('customer.google.callback'));

    $this->assertAuthenticated('customer');
    $this->assertGuest('web');
    $this->get('/backoffice')->assertRedirect('/auth/login');
});

test('a logged-out visitor to the account is sent to the customer login, not the staff one', function () {
    $this->get(route('account.index'))->assertRedirect('/masuk');
});

test('a verified sign-in brings past guest orders into the account', function () {
    enableGoogle();
    guestOrder('ORD-OLD-1', 'Yohana@Example.com');
    guestOrder('ORD-OTHER', 'someone-else@example.com');
    fakeGoogleUser(email: 'yohana@example.com', verified: true);

    $this->get(route('customer.google.callback'));

    expect(Order::where('order_number', 'ORD-OLD-1')->value('customer_id'))->toBe(Customer::first()->id);
    expect(Order::where('order_number', 'ORD-OTHER')->value('customer_id'))->toBeNull();

    $this->get(route('account.orders'))->assertInertia(fn ($page) => $page
        ->has('orders.data', 1)
        ->where('orders.data.0.order_number', 'ORD-OLD-1'));
});

test('an unverified Google email claims nothing', function () {
    enableGoogle();
    guestOrder('ORD-OLD-1', 'yohana@example.com');
    fakeGoogleUser(email: 'yohana@example.com', verified: false);

    $this->get(route('customer.google.callback'));

    expect(Order::where('order_number', 'ORD-OLD-1')->value('customer_id'))->toBeNull();
    $this->get(route('account.orders'))->assertInertia(fn ($page) => $page->has('orders.data', 0));
});

test('an unverified Google email cannot take over an existing account', function () {
    enableGoogle();
    Customer::create(['name' => 'Asli', 'email' => 'yohana@example.com']);
    fakeGoogleUser(id: 'attacker', email: 'yohana@example.com', verified: false);

    $this->get(route('customer.google.callback'))->assertRedirect(route('customer.login'));

    $this->assertGuest('customer');
    expect(Customer::first()->google_id)->toBeNull();
});

test('a returning customer is matched by Google id and keeps one account', function () {
    enableGoogle();

    $as = fn (string $name) => (new GoogleUser)
        ->setRaw(['sub' => 'g-1', 'email' => 'yohana@example.com', 'email_verified' => true, 'name' => $name])
        ->map(['id' => 'g-1', 'name' => $name, 'email' => 'yohana@example.com', 'avatar' => null]);

    // One provider answering both sign-ins in turn; a second facade
    // expectation would never be reached, since Mockery serves the first.
    $provider = Mockery::mock();
    $provider->shouldReceive('user')->andReturn($as('Nama Lama'), $as('Nama Baru'));
    Socialite::shouldReceive('driver')->with('google')->andReturn($provider);

    $this->get(route('customer.google.callback'));
    $this->post(route('customer.logout'));
    $this->get(route('customer.google.callback'));

    expect(Customer::count())->toBe(1);
    expect(Customer::first()->name)->toBe('Nama Baru');
});

test('a cancelled or failed Google sign-in returns to the login page with a message', function () {
    enableGoogle();
    $provider = Mockery::mock();
    $provider->shouldReceive('user')->andThrow(new RuntimeException('access_denied'));
    Socialite::shouldReceive('driver')->with('google')->andReturn($provider);

    $this->get(route('customer.google.callback'))
        ->assertRedirect(route('customer.login'))
        ->assertSessionHasErrors('google');

    $this->assertGuest('customer');
});

test('one customer never sees another customer\'s orders', function () {
    $mine = Customer::create(['name' => 'A', 'email' => 'a@example.com', 'email_verified_at' => now()]);
    $theirs = Customer::create(['name' => 'B', 'email' => 'b@example.com', 'email_verified_at' => now()]);
    guestOrder('ORD-A', 'a@example.com')->update(['customer_id' => $mine->id]);
    guestOrder('ORD-B', 'b@example.com')->update(['customer_id' => $theirs->id]);

    $this->actingAs($mine, 'customer')
        ->get(route('account.orders'))
        ->assertInertia(fn ($page) => $page
            ->has('orders.data', 1)
            ->where('orders.data.0.order_number', 'ORD-A'));
});

test('an order placed while signed in belongs to the account', function () {
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

    $customer = Customer::create(['name' => 'A', 'email' => 'a@example.com']);
    $product = Product::create(['name' => 'Kopi', 'price' => 60000, 'stock' => 5, 'weight_gram' => 250, 'is_active' => true]);

    $this->actingAs($customer, 'customer');
    $this->post(route('cart.store'), ['product_id' => $product->id, 'quantity' => 1]);
    $this->post(route('checkout.store'), [
        'customer_name' => 'A', 'customer_email' => 'different@example.com', 'customer_phone' => '0812',
        'shipping_address' => 'Jl. Test', 'shipping_city' => 'Jayapura', 'shipping_province' => 'Papua',
        'shipping_postal_code' => '99111', 'destination_area_id' => 'AREA-1', 'destination_area_name' => 'Jayapura',
        'courier_code' => 'jne', 'courier_service_code' => 'reg', 'notes' => null,
    ]);

    expect(Order::first()->customer_id)->toBe($customer->id);
});

test('signing out keeps the cart', function () {
    $customer = Customer::create(['name' => 'A', 'email' => 'a@example.com']);
    $product = Product::create(['name' => 'Kopi', 'price' => 60000, 'stock' => 5, 'weight_gram' => 250, 'is_active' => true]);

    $this->actingAs($customer, 'customer');
    $this->post(route('cart.store'), ['product_id' => $product->id, 'quantity' => 2]);
    $this->post(route('customer.logout'))->assertRedirect(route('home'));

    $this->assertGuest('customer');
    expect(session('cart'))->toBe([$product->id => 2]);
});

test('a customer can update their name and phone', function () {
    $customer = Customer::create(['name' => 'A', 'email' => 'a@example.com']);

    $this->actingAs($customer, 'customer')
        ->put(route('account.profile'), ['name' => 'Yohana', 'phone' => '081299'])
        ->assertSessionHasNoErrors();

    expect($customer->fresh()->only('name', 'phone'))->toBe(['name' => 'Yohana', 'phone' => '081299']);
});

test('a guest can find an order with its number and email', function () {
    guestOrder('ORD-20260924-ABC123', 'yohana@example.com');

    $response = $this->post(route('track.lookup'), [
        'order_number' => 'ord-20260924-abc123',
        'email' => 'YOHANA@example.com',
    ]);

    expect($response->headers->get('Location'))
        ->toContain('/pesanan/ORD-20260924-ABC123')
        ->toContain('signature=');
});

test('a wrong email reveals nothing about the order', function () {
    guestOrder('ORD-20260924-ABC123', 'yohana@example.com');

    $this->from(route('track.show'))
        ->post(route('track.lookup'), ['order_number' => 'ORD-20260924-ABC123', 'email' => 'wrong@example.com'])
        ->assertRedirect(route('track.show'))
        ->assertSessionHasErrors('order_number');
});

test('order lookup is rate limited', function () {
    foreach (range(1, 10) as $_) {
        $this->post(route('track.lookup'), ['order_number' => 'X', 'email' => 'a@example.com']);
    }

    $this->post(route('track.lookup'), ['order_number' => 'X', 'email' => 'a@example.com'])->assertStatus(429);
});

test('order summaries count distinct lines separately from units', function () {
    $customer = Customer::create(['name' => 'A', 'email' => 'a@example.com']);
    $order = guestOrder('ORD-LINES', 'a@example.com');
    $order->update(['customer_id' => $customer->id]);
    $order->items()->create(['product_name' => 'Kopi', 'unit_price' => 500, 'quantity' => 2, 'subtotal' => 1000]);

    $this->actingAs($customer, 'customer')
        ->get(route('account.orders'))
        ->assertInertia(fn ($page) => $page
            ->where('orders.data.0.item_count', 2)
            ->where('orders.data.0.line_count', 1));
});
