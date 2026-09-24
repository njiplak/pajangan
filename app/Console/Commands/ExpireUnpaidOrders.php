<?php

namespace App\Console\Commands;

use App\Contract\Notification\OrderNotifierContract;
use App\Contract\Payment\PaymentStatus;
use App\Contract\Setting\SettingContract;
use App\Models\Order;
use App\Service\Order\OrderStockReleaser;
use Illuminate\Console\Command;

/**
 * Cancels orders that were never paid for and puts their stock back.
 *
 * Without this, one abandoned checkout holds its products out of the
 * catalogue permanently — and since a bundle draws on several products at
 * once, a single abandoned bundle order can make a handful of listings
 * look sold out while nothing has actually sold.
 */
class ExpireUnpaidOrders extends Command
{
    protected $signature = 'orders:expire-unpaid {--hours= : Override the configured hold window}';

    protected $description = 'Cancel unpaid pending orders past the hold window and return their stock';

    public const DEFAULT_HOLD_HOURS = 24;

    public function handle(
        SettingContract $settings,
        OrderStockReleaser $releaser,
        OrderNotifierContract $notifier,
    ): int {
        $hours = (int) ($this->option('hours')
            ?? ($settings->allAsKeyValue()['order_unpaid_hold_hours'] ?? self::DEFAULT_HOLD_HOURS));

        if ($hours < 1) {
            $this->error('Hold window must be at least 1 hour.');

            return self::FAILURE;
        }

        $cutoff = now()->subHours($hours);

        $orders = Order::query()
            ->where('status', Order::STATUS_PENDING)
            ->where(function ($query) {
                $query->whereNull('payment_status')
                    ->orWhere('payment_status', '!=', PaymentStatus::PAID);
            })
            // Never touch an order whose stock already went back.
            ->whereNull('stock_released_at')
            ->where('created_at', '<', $cutoff)
            ->get();

        $released = 0;

        foreach ($orders as $order) {
            if (! $releaser->release($order)) {
                // Another process got there first.
                continue;
            }

            $order->update(['status' => Order::STATUS_CANCELLED]);
            $notifier->orderCancelled($order->refresh());

            $released++;
            $this->line("Cancelled and restocked {$order->order_number}");
        }

        $this->info("Expired {$released} unpaid order(s) older than {$hours}h.");

        return self::SUCCESS;
    }
}
