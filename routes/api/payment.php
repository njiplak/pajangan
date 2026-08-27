<?php

use App\Http\Controllers\Payment\PaymentCallbackController;
use Illuminate\Support\Facades\Route;

Route::post('/payment/callback/{gateway}', [PaymentCallbackController::class, 'handle'])->name('payment.callback');
