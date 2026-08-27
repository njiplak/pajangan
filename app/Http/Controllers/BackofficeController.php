<?php

namespace App\Http\Controllers;

use App\Contract\Setting\SettingContract;
use App\Models\Order;
use App\Models\Product;
use Illuminate\Support\Facades\DB;
use Inertia\Inertia;

class BackofficeController extends Controller
{
    public function __construct(private readonly SettingContract $settings) {}

    public function index()
    {
        $lowStockThreshold = (int) ($this->settings->allAsKeyValue()['low_stock_threshold'] ?? 10);

        $paidOrders = Order::query()
            ->whereNotNull('paid_at')
            ->where('status', '!=', Order::STATUS_CANCELLED);

        $revenueToday = (clone $paidOrders)->whereDate('paid_at', today())->sum('total');
        $revenueMonth = (clone $paidOrders)->whereBetween('paid_at', [now()->startOfMonth(), now()->endOfMonth()])->sum('total');

        $statusCounts = Order::query()
            ->select('status', DB::raw('count(*) as count'))
            ->groupBy('status')
            ->pluck('count', 'status');

        $ordersByStatus = collect(Order::STATUSES)->mapWithKeys(
            fn (string $status) => [$status => (int) ($statusCounts[$status] ?? 0)]
        );

        $trendStart = now()->subDays(13)->startOfDay();
        $trendRows = (clone $paidOrders)
            ->where('paid_at', '>=', $trendStart)
            ->select(DB::raw('DATE(paid_at) as day'), DB::raw('SUM(total) as revenue'))
            ->groupBy('day')
            ->pluck('revenue', 'day');

        $revenueTrend = collect(range(13, 0))->map(function (int $daysAgo) use ($trendRows) {
            $date = now()->subDays($daysAgo)->toDateString();

            return [
                'date' => $date,
                'revenue' => (int) ($trendRows[$date] ?? 0),
            ];
        });

        $lowStockProducts = Product::query()
            ->where('is_active', true)
            ->where('stock', '<=', $lowStockThreshold)
            ->orderBy('stock')
            ->get(['id', 'name', 'stock']);

        $recentOrders = Order::query()
            ->latest()
            ->take(8)
            ->get(['id', 'order_number', 'customer_name', 'total', 'status', 'created_at']);

        return Inertia::render('backoffice', [
            'revenueToday' => (int) $revenueToday,
            'revenueMonth' => (int) $revenueMonth,
            'pendingOrdersCount' => $ordersByStatus[Order::STATUS_PENDING],
            'lowStockCount' => $lowStockProducts->count(),
            'ordersByStatus' => $ordersByStatus,
            'revenueTrend' => $revenueTrend,
            'lowStockProducts' => $lowStockProducts,
            'recentOrders' => $recentOrders,
        ]);
    }
}
