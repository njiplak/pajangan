<?php

use App\Http\Controllers\Shipping\ShippingWebhookController;
use Illuminate\Support\Facades\Route;

Route::post('/webhook/shipping/biteship/{token}', [ShippingWebhookController::class, 'handle'])->name('webhook.shipping.biteship');
