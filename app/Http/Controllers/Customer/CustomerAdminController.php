<?php

namespace App\Http\Controllers\Customer;

use App\Contract\Customer\CustomerContract;
use App\Http\Controllers\Controller;
use App\Models\Customer;
use App\Models\Order;
use App\Utils\ListFilter;
use Inertia\Inertia;

/**
 * Read-only staff view of storefront customers. Gated on order.view:
 * a customer record here is order data, not account administration.
 */
class CustomerAdminController extends Controller
{
    public function __construct(private readonly CustomerContract $service) {}

    public function index()
    {
        return Inertia::render('customer/index');
    }

    public function fetch()
    {
        $data = $this->service->all(
            allowedFilters: [ListFilter::search(['name', 'email', 'phone'])],
            allowedSorts: [],
            withPaginate: true,
            perPage: request()->get('per_page', 10),
            orderColumn: 'created_at',
            orderPosition: 'desc',
        );

        return response()->json($data);
    }

    public function show($id)
    {
        $customer = $this->service->find((int) $id, ['addresses']);

        if ($customer instanceof \Exception) {
            abort(404);
        }

        /** @var Customer $customer */
        $orders = $customer->visibleOrders()->latest()->get(['id', 'order_number', 'status', 'total', 'paid_at', 'created_at']);

        return Inertia::render('customer/show', [
            'customer' => $customer->only(['id', 'name', 'email', 'phone', 'email_verified_at', 'created_at']),
            'addresses' => $customer->addresses->map->only([
                'id', 'label', 'recipient_name', 'phone', 'address', 'city', 'province', 'postal_code', 'is_default',
            ])->values(),
            'orders' => $orders,
            'totalSpent' => (int) $orders
                ->filter(fn (Order $order) => $order->paid_at !== null && $order->status !== Order::STATUS_CANCELLED)
                ->sum('total'),
        ]);
    }
}
