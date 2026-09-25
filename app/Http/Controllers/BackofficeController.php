<?php

namespace App\Http\Controllers;

use App\Models\Order;
use App\Models\Product;
use App\Service\Stock\StockLedger;
use Illuminate\Support\Facades\DB;
use Inertia\Inertia;

class BackofficeController extends Controller
{
    public function __construct(private readonly StockLedger $ledger) {}

    public function index()
    {
        $lowStockThreshold = $this->ledger->lowStockThreshold();

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
            // Bundles hold no stock of their own; their components are
            // already listed here in their own right.
            ->where('is_bundle', false)
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
