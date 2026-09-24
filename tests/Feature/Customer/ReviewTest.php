<?php

use App\Mail\OrderDeliveredMail;
use App\Models\Customer;
use App\Models\Order;
use App\Models\Product;
use App\Models\ProductReview;
use App\Models\User;
use Spatie\Permission\Models\Permission;

function reviewer(string $email = 'a@example.com', string $name = 'Yohana Wenda'): Customer
{
    return Customer::create(['name' => $name, 'email' => $email, 'email_verified_at' => now()]);
}

function reviewProduct(string $name = 'Kopi Wamena'): Product
{
    return Product::create(['name' => $name, 'price' => 60000, 'stock' => 10, 'weight_gram' => 250, 'is_active' => true]);
}

function purchase(Customer $customer, Product $product, string $status = Order::STATUS_COMPLETED): Order
{
    $order = Order::create([
        'customer_id' => $customer->id,
        'order_number' => 'ORD-RV-'.uniqid(),
        'customer_name' => $customer->name, 'customer_email' => $customer->email, 'customer_phone' => '0812',
        'shipping_address' => 'Jl. Test', 'shipping_city' => 'Jayapura', 'shipping_province' => 'Papua',
        'status' => $status, 'subtotal' => 60000, 'total' => 60000,
    ]);
    $order->items()->create(['product_id' => $product->id, 'product_name' => $product->name, 'unit_price' => 60000, 'quantity' => 1, 'subtotal' => 60000]);

    return $order;
}

test('a customer who received the product can review it', function () {
    $c = reviewer();
    $p = reviewProduct();
    purchase($c, $p);

    $this->actingAs($c, 'customer')->get(route('products.show', $p->slug))
        ->assertInertia(fn ($page) => $page->where('canReview', true));

    $this->post(route('account.reviews.store', $p->id), ['rating' => 5, 'body' => 'Harum sekali.'])
        ->assertSessionHasNoErrors();

    expect(ProductReview::first()->only('rating', 'body', 'is_visible'))
        ->toBe(['rating' => 5, 'body' => 'Harum sekali.', 'is_visible' => true]);
});

test('someone who has not received the product cannot review it', function () {
    $c = reviewer();
    $p = reviewProduct();
    purchase($c, $p, Order::STATUS_SHIPPED);

    $this->actingAs($c, 'customer')
        ->post(route('account.reviews.store', $p->id), ['rating' => 5])
        ->assertSessionHasErrors('review');

    expect(ProductReview::count())->toBe(0);
});

test('a customer who never bought the product cannot review it', function () {
    $c = reviewer();
    $p = reviewProduct();
    purchase($c, reviewProduct('Madu'));

    $this->actingAs($c, 'customer')->post(route('account.reviews.store', $p->id), ['rating' => 1])->assertSessionHasErrors('review');
});

test('a guest cannot review', function () {
    $p = reviewProduct();

    $this->post(route('account.reviews.store', $p->id), ['rating' => 5])->assertRedirect('/masuk');
});

test('reviewing again edits the review rather than adding a second vote', function () {
    $c = reviewer();
    $p = reviewProduct();
    purchase($c, $p);
    purchase($c, $p);

    $this->actingAs($c, 'customer')->post(route('account.reviews.store', $p->id), ['rating' => 2]);
    $this->post(route('account.reviews.store', $p->id), ['rating' => 4, 'body' => 'Setelah dicoba lagi, enak.']);

    expect(ProductReview::count())->toBe(1);
    expect(ProductReview::first()->rating)->toBe(4);
});

test('a rating must be between 1 and 5', function () {
    $c = reviewer();
    $p = reviewProduct();
    purchase($c, $p);

    $this->actingAs($c, 'customer')->post(route('account.reviews.store', $p->id), ['rating' => 6])->assertSessionHasErrors('rating');
    $this->post(route('account.reviews.store', $p->id), ['rating' => 0])->assertSessionHasErrors('rating');
});

test('the product page shows published reviews and their average, not hidden ones', function () {
    $p = reviewProduct();

    foreach ([[5, true, 'a@example.com'], [3, true, 'b@example.com'], [1, false, 'c@example.com']] as [$rating, $visible, $email]) {
        $c = reviewer($email);
        ProductReview::create(['product_id' => $p->id, 'customer_id' => $c->id, 'rating' => $rating, 'is_visible' => $visible]);
    }

    $this->get(route('products.show', $p->slug))->assertInertia(fn ($page) => $page
        ->has('reviews', 2)
        ->where('product.rating_avg', 4)
        ->where('product.rating_count', 2));
});

test('a review shows only a first name and initial publicly', function () {
    $p = reviewProduct();
    $c = reviewer(name: 'Yohana Wenda Kogoya');
    ProductReview::create(['product_id' => $p->id, 'customer_id' => $c->id, 'rating' => 5]);

    $this->get(route('products.show', $p->slug))->assertInertia(fn ($page) => $page
        ->where('reviews.0.author', 'Yohana W.')
        ->missing('reviews.0.email'));
});

test('staff can hide a review and the customer cannot republish it by editing', function () {
    $c = reviewer();
    $p = reviewProduct();
    purchase($c, $p);
    $this->actingAs($c, 'customer')->post(route('account.reviews.store', $p->id), ['rating' => 1, 'body' => 'spam']);
    $review = ProductReview::first();

    $staff = User::factory()->create();
    Permission::firstOrCreate(['name' => 'product.update', 'guard_name' => 'web']);
    $staff->givePermissionTo('product.update');

    $this->actingAs($staff)->put(route('backoffice.review.visibility', $review->id), ['is_visible' => false]);
    expect($review->fresh()->is_visible)->toBeFalse();

    $this->actingAs($c, 'customer')->post(route('account.reviews.store', $p->id), ['rating' => 5, 'body' => 'edited']);
    expect($review->fresh()->is_visible)->toBeFalse();
    expect($review->fresh()->body)->toBe('edited');
});

test('moderating reviews requires the product permission', function () {
    $review = ProductReview::create(['product_id' => reviewProduct()->id, 'customer_id' => reviewer()->id, 'rating' => 5]);

    $this->actingAs(User::factory()->create())
        ->put(route('backoffice.review.visibility', $review->id), ['is_visible' => false])
        ->assertForbidden();

    expect($review->fresh()->is_visible)->toBeTrue();
});

test('a customer can delete their own review', function () {
    $c = reviewer();
    $p = reviewProduct();
    ProductReview::create(['product_id' => $p->id, 'customer_id' => $c->id, 'rating' => 5]);

    $this->actingAs($c, 'customer')->delete(route('account.reviews.destroy', $p->id));

    expect(ProductReview::count())->toBe(0);
});

test('the delivery email invites a review for each product still on sale', function () {
    $c = reviewer();
    $kopi = reviewProduct('Kopi Wamena');
    $order = purchase($c, $kopi);
    $order->items()->create(['product_id' => null, 'product_name' => 'Produk Lama', 'unit_price' => 1, 'quantity' => 1, 'subtotal' => 1]);

    $html = (new OrderDeliveredMail($order->load('items.product')))->render();

    expect($html)->toContain(route('products.show', $kopi->slug));
    expect($html)->toContain('Bagaimana produknya?');
});
