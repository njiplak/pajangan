<?php

use App\Http\Controllers\Customer\AccountController;
use App\Http\Controllers\Customer\AddressController;
use App\Http\Controllers\Customer\CustomerAuthController;
use App\Http\Controllers\Customer\ReviewController;
use App\Http\Controllers\Customer\TrackOrderController;
use App\Http\Controllers\Customer\WishlistController;
use App\Http\Controllers\Storefront\CartController;
use App\Http\Controllers\Storefront\CheckoutController;
use App\Http\Controllers\Storefront\OrderLookupController;
use App\Http\Controllers\Storefront\PageController;
use App\Http\Controllers\Storefront\ProductController;
use App\Http\Controllers\Storefront\ShippingController;
use Illuminate\Support\Facades\Route;

Route::get('/produk', [ProductController::class, 'index'])->name('products.index');
Route::get('/produk/{slug}', [ProductController::class, 'show'])->name('products.show');

Route::get('/keranjang', [CartController::class, 'index'])->name('cart.index')->middleware('checkout-mode');
Route::post('/keranjang', [CartController::class, 'store'])->name('cart.store')->middleware('checkout-mode');
Route::put('/keranjang/{productId}', [CartController::class, 'update'])->name('cart.update')->middleware('checkout-mode');
Route::delete('/keranjang/{productId}', [CartController::class, 'destroy'])->name('cart.destroy')->middleware('checkout-mode');

Route::get('/checkout', [CheckoutController::class, 'index'])->name('checkout.index')->middleware('checkout-mode');
Route::post('/checkout', [CheckoutController::class, 'store'])->name('checkout.store')->middleware('checkout-mode');

Route::get('/checkout/shipping/areas', [ShippingController::class, 'searchAreas'])->name('checkout.shipping-areas')->middleware('checkout-mode');
Route::post('/checkout/shipping/rates', [ShippingController::class, 'rates'])->name('checkout.shipping-rates')->middleware('checkout-mode');

Route::post('/pengiriman/tujuan', [ShippingController::class, 'setDestination'])->name('shipping.destination')->middleware('checkout-mode');
Route::get('/pengiriman/estimasi', [ShippingController::class, 'estimate'])->name('shipping.estimate')->middleware('checkout-mode');

Route::get('/pesanan/{order:order_number}', [OrderLookupController::class, 'show'])
    ->name('order.show')
    ->middleware('signed');

Route::post('/pesanan/{order:order_number}/bayar', [OrderLookupController::class, 'pay'])
    ->name('order.pay')
    ->middleware(['signed', 'checkout-mode']);

Route::post('/pesanan/{order:order_number}/beli-lagi', [OrderLookupController::class, 'reorder'])
    ->name('order.reorder')
    ->middleware(['signed', 'checkout-mode']);

Route::get('/tentang-kami', [PageController::class, 'about'])->name('about');

// Customer accounts — Google sign-in only. Guest checkout is unaffected.
Route::get('/masuk', [CustomerAuthController::class, 'login'])->name('customer.login');
Route::get('/auth/google', [CustomerAuthController::class, 'redirect'])->name('customer.google');
Route::get('/auth/google/callback', [CustomerAuthController::class, 'callback'])->name('customer.google.callback');
Route::post('/keluar', [CustomerAuthController::class, 'logout'])->name('customer.logout');

Route::middleware('auth:customer')->prefix('akun')->name('account.')->group(function () {
    Route::get('/', [AccountController::class, 'index'])->name('index');
    Route::get('/pesanan', [AccountController::class, 'orders'])->name('orders');
    Route::put('/profil', [AccountController::class, 'updateProfile'])->name('profile');

    Route::get('/alamat', [AddressController::class, 'index'])->name('addresses.index');
    Route::post('/alamat', [AddressController::class, 'store'])->name('addresses.store');
    Route::put('/alamat/{address}', [AddressController::class, 'update'])->name('addresses.update');
    Route::post('/alamat/{address}/utama', [AddressController::class, 'makeDefault'])->name('addresses.default');
    Route::delete('/alamat/{address}', [AddressController::class, 'destroy'])->name('addresses.destroy');

    Route::get('/wishlist', [WishlistController::class, 'index'])->name('wishlist.index');
    Route::post('/wishlist/{product}', [WishlistController::class, 'toggle'])->name('wishlist.toggle');

    Route::post('/ulasan/{product}', [ReviewController::class, 'store'])
        ->name('reviews.store')
        ->middleware('throttle:20,1');
    Route::delete('/ulasan/{product}', [ReviewController::class, 'destroy'])->name('reviews.destroy');
});

Route::get('/lacak-pesanan', [TrackOrderController::class, 'show'])->name('track.show');
Route::post('/lacak-pesanan', [TrackOrderController::class, 'lookup'])
    ->name('track.lookup')
    ->middleware('throttle:10,1');
