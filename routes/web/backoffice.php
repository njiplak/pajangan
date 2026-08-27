<?php

use App\Http\Controllers\BackofficeController;
use App\Http\Controllers\Banner\BannerController;
use App\Http\Controllers\Order\OrderController;
use App\Http\Controllers\Page\PageController;
use App\Http\Controllers\Product\ProductController;
use Illuminate\Support\Facades\Route;

Route::group(['middleware' => 'auth', 'prefix' => 'backoffice', 'as' => 'backoffice.'], function () {
    Route::get('/', [BackofficeController::class, 'index'])->name('index');

    Route::group(['prefix' => 'product', 'as' => 'product.'], function () {
        Route::get('/', [ProductController::class, 'index'])->name('index')->middleware('permission:product.view');
        Route::get('/fetch', [ProductController::class, 'fetch'])->name('fetch')->middleware('permission:product.view');
        Route::get('/create', [ProductController::class, 'create'])->name('create')->middleware('permission:product.create');
        Route::post('/', [ProductController::class, 'store'])->name('store')->middleware('permission:product.create');
        Route::get('/{id}', [ProductController::class, 'show'])->name('show')->middleware('permission:product.update');
        Route::put('/{id}', [ProductController::class, 'update'])->name('update')->middleware('permission:product.update');
        Route::delete('/{id}', [ProductController::class, 'destroy'])->name('destroy')->middleware('permission:product.delete');
        Route::post('/destroy-bulk', [ProductController::class, 'destroy_bulk'])->name('destroy-bulk')->middleware('permission:product.delete');
    });

    Route::group(['prefix' => 'page', 'as' => 'page.'], function () {
        Route::get('/', [PageController::class, 'index'])->name('index')->middleware('permission:page.view');
        Route::get('/fetch', [PageController::class, 'fetch'])->name('fetch')->middleware('permission:page.view');
        Route::get('/create', [PageController::class, 'create'])->name('create')->middleware('permission:page.create');
        Route::post('/', [PageController::class, 'store'])->name('store')->middleware('permission:page.create');
        Route::get('/{id}', [PageController::class, 'show'])->name('show')->middleware('permission:page.update');
        Route::put('/{id}', [PageController::class, 'update'])->name('update')->middleware('permission:page.update');
        Route::delete('/{id}', [PageController::class, 'destroy'])->name('destroy')->middleware('permission:page.delete');
        Route::post('/destroy-bulk', [PageController::class, 'destroy_bulk'])->name('destroy-bulk')->middleware('permission:page.delete');
    });

    Route::group(['prefix' => 'banner', 'as' => 'banner.'], function () {
        Route::get('/', [BannerController::class, 'index'])->name('index')->middleware('permission:banner.view');
        Route::get('/fetch', [BannerController::class, 'fetch'])->name('fetch')->middleware('permission:banner.view');
        Route::get('/create', [BannerController::class, 'create'])->name('create')->middleware('permission:banner.create');
        Route::post('/', [BannerController::class, 'store'])->name('store')->middleware('permission:banner.create');
        Route::get('/{id}', [BannerController::class, 'show'])->name('show')->middleware('permission:banner.update');
        Route::put('/{id}', [BannerController::class, 'update'])->name('update')->middleware('permission:banner.update');
        Route::delete('/{id}', [BannerController::class, 'destroy'])->name('destroy')->middleware('permission:banner.delete');
        Route::post('/destroy-bulk', [BannerController::class, 'destroy_bulk'])->name('destroy-bulk')->middleware('permission:banner.delete');
    });

    Route::group(['prefix' => 'order', 'as' => 'order.'], function () {
        Route::get('/', [OrderController::class, 'index'])->name('index')->middleware('permission:order.view');
        Route::get('/fetch', [OrderController::class, 'fetch'])->name('fetch')->middleware('permission:order.view');
        Route::get('/shipping/areas', [OrderController::class, 'searchShippingAreas'])->name('shipping-areas')->middleware('permission:order.update');
        Route::get('/{id}', [OrderController::class, 'show'])->name('show')->middleware('permission:order.view');
        Route::post('/{id}/shipping/rates', [OrderController::class, 'quoteShippingRates'])->name('shipping-rates')->middleware('permission:order.update');
        Route::post('/{id}/shipping/create', [OrderController::class, 'createShipment'])->name('shipping-create')->middleware('permission:order.update');
        Route::post('/{id}/shipping/cancel', [OrderController::class, 'cancelShipment'])->name('shipping-cancel')->middleware('permission:order.update');
        Route::put('/{id}/shipping', [OrderController::class, 'updateShipping'])->name('update-shipping')->middleware('permission:order.update');
        Route::put('/{id}/status', [OrderController::class, 'updateStatus'])->name('update-status')->middleware('permission:order.update');
    });
});
