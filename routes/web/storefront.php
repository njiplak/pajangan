<?php

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

Route::get('/pesanan/{order:order_number}', [OrderLookupController::class, 'show'])
    ->name('order.show')
    ->middleware('signed');

Route::get('/tentang-kami', [PageController::class, 'about'])->name('about');
