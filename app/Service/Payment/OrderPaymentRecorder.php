<?php

namespace App\Service\Payment;

use App\Contract\Notification\OrderNotifierContract;
use App\Contract\Notification\StaffOrderNotifierContract;
use App\Contract\Payment\PaymentStatus;
use App\Models\Order;
use App\Service\Order\OrderActivityLog;
use Illuminate\Support\Facades\DB;

/**
 * Applies a gateway's word on an order's payment — from a webhook or from
 * asking the gateway directly — so both paths follow the same rules and
 * send the same mail.
 */
class OrderPaymentRecorder
{
    public const NEWLY_PAID = 'newly_paid';

    /** Paid after the order was already cancelled: money is now owed back. */
    public const LATE_PAID = 'late_paid';

    public const UPDATED = 'updated';

    public const UNCHANGED = 'unchanged';

    public function __construct(
        private readonly OrderNotifierContract $notifier,
        private readonly StaffOrderNotifierContract $staffNotifier,
        private readonly OrderActivityLog $activity,
    ) {}

    /**
     * @param  array{status: string, paid_at?: mixed, raw?: array}  $report
     */
    public function record(Order $order, array $report): string
    {
        [$outcome, $saved] = DB::transaction(function () use ($order, $report) {
            $locked = Order::query()->whereKey($order->id)->lockForUpdate()->firstOrFail();

            // Terminal: a stale or out-of-order report never downgrades a paid order.
            if ($locked->payment_status === PaymentStatus::PAID || $locked->payment_status === PaymentStatus::REFUNDED) {
                return [self::UNCHANGED, $locked];
            }

            if ($locked->payment_status === $report['status']) {
                return [self::UNCHANGED, $locked];
            }

            $locked->payment_status = $report['status'];
            $locked->payment_payload = $report['raw'] ?? $locked->payment_payload;
            $gateway = $locked->payment_gateway ?? 'gateway';

            if ($report['status'] !== PaymentStatus::PAID) {
                $locked->save();
                $this->activity->record($locked, 'payment', "Status pembayaran di {$gateway}: {$report['status']}.");

                return [self::UPDATED, $locked];
            }

            $locked->paid_at = $report['paid_at'] ?? now();

            // A cancelled order stays cancelled: its stock may already be
            // sold to someone else, so the money goes back instead.
            if ($locked->status === Order::STATUS_CANCELLED) {
                $locked->save();
                $this->activity->record($locked, 'payment', "Pembayaran via {$gateway} masuk setelah pesanan dibatalkan — dana perlu dikembalikan.");

                return [self::LATE_PAID, $locked];
            }

            $locked->status = Order::STATUS_PAID;
            $locked->save();
            $this->activity->record($locked, 'payment', "Pembayaran diterima via {$gateway}.");

            return [self::NEWLY_PAID, $locked];
        });

        // Outside the transaction: mail only for a write that committed.
        if ($outcome === self::NEWLY_PAID) {
            $this->notifier->paymentReceived($saved);
            $this->staffNotifier->orderPaid($saved);
        } elseif ($outcome === self::LATE_PAID) {
            // paid_at is now set, so this renders the refund wording.
            $this->notifier->orderCancelled($saved);
            $this->staffNotifier->refundNeeded($saved);
        }

        return $outcome;
    }
}
