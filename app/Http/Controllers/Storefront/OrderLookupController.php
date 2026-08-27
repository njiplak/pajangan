<?php

namespace App\Http\Controllers\Storefront;

use App\Http\Controllers\Controller;
use App\Models\Order;
use Inertia\Inertia;

class OrderLookupController extends Controller
{
    public function show(Order $order)
    {
        $order->load('items');

        return Inertia::render('storefront/orders/show', [
            'order' => $order,
        ]);
    }
}
