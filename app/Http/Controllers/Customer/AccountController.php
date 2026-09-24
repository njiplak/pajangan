<?php

namespace App\Http\Controllers\Customer;

use App\Http\Controllers\Controller;
use App\Models\Customer;
use App\Models\Order;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\URL;
use Inertia\Inertia;

class AccountController extends Controller
{
    public function index()
    {
        $customer = $this->customer();

        return Inertia::render('storefront/account/index', [
            'profile' => [
                'name' => $customer->name,
                'email' => $customer->email,
                'phone' => $customer->phone,
                'avatar_url' => $customer->avatar_url,
            ],
            'recentOrders' => $customer->visibleOrders()->with('items')->latest()->take(3)->get()
                ->map(fn (Order $order) => $this->summarise($order))->values(),
            'orderCount' => $customer->visibleOrders()->count(),
        ]);
    }

    public function orders()
    {
        $orders = $this->customer()->visibleOrders()->with('items')->latest()->paginate(10);

        return Inertia::render('storefront/account/orders', [
            'orders' => $orders->through(fn (Order $order) => $this->summarise($order)),
        ]);
    }

    public function updateProfile(Request $request)
    {
        $validated = $request->validate([
            'name' => ['required', 'string', 'max:255'],
            'phone' => ['nullable', 'string', 'max:30'],
        ]);

        $this->customer()->update($validated);

        return back();
    }

    /**
     * @return array<string, mixed>
     */
    private function summarise(Order $order): array
    {
        return [
            'order_number' => $order->order_number,
            'status' => $order->status,
            'created_at' => $order->created_at?->toIso8601String(),
            'total' => (int) $order->total,
            'item_count' => (int) $order->items->sum('quantity'),
            // Distinct lines, not units: two bags of one coffee is one line.
            'line_count' => $order->items->count(),
            'item_names' => $order->items->pluck('product_name')->take(3)->values(),
            // The same signed link the emails carry; the order page itself
            // stays reachable without an account.
            'url' => URL::signedRoute('order.show', ['order' => $order->order_number]),
        ];
    }

    private function customer(): Customer
    {
        /** @var Customer */
        return Auth::guard('customer')->user();
    }
}
