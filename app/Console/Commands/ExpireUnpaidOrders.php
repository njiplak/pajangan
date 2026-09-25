<?php

namespace App\Console\Commands;

use App\Contract\Notification\OrderNotifierContract;
use App\Contract\Payment\PaymentStatus;
use App\Models\Order;
use App\Service\Order\OrderActivityLog;
use App\Service\Order\OrderStockReleaser;
use App\Service\Order\UnpaidHoldWindow;
use App\Service\Payment\OrderPaymentRecorder;
use App\Service\Payment\PaymentReconciler;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Throwable;

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
    protected $signature = 'orders:expire-unpaid {--hours= : Override the configured hold window for storefront orders}';

    protected $description = 'Cancel unpaid pending orders past the hold window and return their stock';

    public function handle(
        UnpaidHoldWindow $window,
        OrderStockReleaser $releaser,
        OrderNotifierContract $notifier,
        PaymentReconciler $reconciler,
        OrderActivityLog $activity,
    ): int {
        $hours = (int) ($this->option('hours') ?? $window->onlineHours());
        $manualHours = $window->manualHours();

        if ($hours < 1) {
            $this->error('Hold window must be at least 1 hour.');

            return self::FAILURE;
        }

        $orders = Order::query()
            ->where('status', Order::STATUS_PENDING)
            ->where(function ($query) {
                $query->whereNull('payment_status')
                    ->orWhere('payment_status', '!=', PaymentStatus::PAID);
            })
            // Never touch an order whose stock already went back.
            ->whereNull('stock_released_at')
            ->where(function ($query) use ($hours, $manualHours) {
                $query->where(fn ($online) => $online
                    ->where('source', '!=', Order::SOURCE_MANUAL)
                    ->where('created_at', '<', now()->subHours($hours)));

                if ($manualHours > 0) {
                    $query->orWhere(fn ($manual) => $manual
                        ->where('source', Order::SOURCE_MANUAL)
                        ->where('created_at', '<', now()->subHours($manualHours)));
                }
            })
            ->get();

        $released = 0;

        foreach ($orders as $order) {
            // Our own record can be wrong when a webhook was lost, and
            // cancelling a paid order tells the customer "no charge" while
            // selling their stock again. Ask the gateway before acting on it.
            if ($reconciler->canCheck($order)) {
                try {
                    $outcome = $reconciler->check($order);
                } catch (Throwable $e) {
                    Log::warning("Not expiring order [{$order->order_number}]: its payment could not be confirmed with gateway [{$order->payment_gateway}]: {$e->getMessage()}");

                    continue;
                }

                if ($outcome === OrderPaymentRecorder::NEWLY_PAID) {
                    $this->line("Found payment for {$order->order_number}; kept it");

                    continue;
                }
            }

            $heldHours = $order->source === Order::SOURCE_MANUAL ? $manualHours : $hours;

            $cancelled = DB::transaction(function () use ($order, $releaser, $activity, $heldHours) {
                // Re-checked under lock: a webhook may have paid it since the query above.
                $locked = Order::query()->whereKey($order->id)->lockForUpdate()->first();

                if (! $locked
                    || $locked->status !== Order::STATUS_PENDING
                    || $locked->payment_status === PaymentStatus::PAID
                    || ! $releaser->release($locked)) {
                    return false;
                }

                $locked->update(['status' => Order::STATUS_CANCELLED]);
                $activity->record($locked, 'status', "Dibatalkan otomatis: belum dibayar setelah {$heldHours} jam.");

                return true;
            });

            if (! $cancelled) {
                continue;
            }

            $notifier->orderCancelled($order->refresh());

            $released++;
            $this->line("Cancelled and restocked {$order->order_number}");
        }

        $this->info("Expired {$released} unpaid order(s) (storefront window {$hours}h, manual window "
            .($manualHours > 0 ? "{$manualHours}h" : 'off').').');

        return self::SUCCESS;
    }
}
