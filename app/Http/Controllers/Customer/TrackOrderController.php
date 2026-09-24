<?php

namespace App\Http\Controllers\Customer;

use App\Http\Controllers\Controller;
use App\Models\Order;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\URL;
use Inertia\Inertia;

/**
 * For guest buyers who lost the email: order number plus the email it was
 * placed with opens the order page. Both are required, so an order number
 * alone — printed on a parcel label, say — reveals nothing.
 */
class TrackOrderController extends Controller
{
    public function show()
    {
        return Inertia::render('storefront/account/track');
    }

    public function lookup(Request $request)
    {
        $validated = $request->validate([
            'order_number' => ['required', 'string', 'max:64'],
            'email' => ['required', 'email', 'max:255'],
        ]);

        $order = Order::query()
            ->where('order_number', mb_strtoupper(trim($validated['order_number'])))
            ->whereRaw('LOWER(customer_email) = ?', [mb_strtolower(trim($validated['email']))])
            ->first();

        if (! $order) {
            // One message for both misses, so this cannot be used to test
            // which order numbers exist.
            return back()->withErrors([
                'order_number' => 'Pesanan tidak ditemukan. Periksa kembali nomor pesanan dan email Anda.',
            ])->onlyInput('order_number', 'email');
        }

        return redirect()->to(URL::signedRoute('order.show', ['order' => $order->order_number]));
    }
}
