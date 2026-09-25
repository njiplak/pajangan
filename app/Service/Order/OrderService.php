<?php

namespace App\Service\Order;

use App\Contract\Notification\OrderNotifierContract;
use App\Contract\Notification\StaffOrderNotifierContract;
use App\Contract\Order\OrderContract;
use App\Contract\Payment\PaymentStatus;
use App\Models\Order;
use App\Service\BaseService;
use Exception;
use Illuminate\Support\Facades\DB;
use RuntimeException;

class OrderService extends BaseService implements OrderContract
{
    protected array $relation = ['items'];

    public function __construct(
        Order $model,
        private readonly OrderStockReleaser $releaser,
        private readonly OrderNotifierContract $notifier,
        private readonly StaffOrderNotifierContract $staffNotifier,
        private readonly OrderActivityLog $activity,
    ) {
        parent::__construct($model);
    }

    public function updateStatus(int $id, string $status)
    {
        try {
            DB::beginTransaction();
            // Locked so a gateway callback landing mid-change can't be
            // overwritten by a transition decided on a stale status.
            $order = $this->model->newQuery()->lockForUpdate()->findOrFail($id);

            $from = $order->status;

            if (! $order->canMoveTo($status)) {
                throw new RuntimeException('Status pesanan tidak bisa diubah dari "'.Order::statusLabel($from).'" ke "'.Order::statusLabel($status).'".');
            }

            $update = ['status' => $status];

            if ($status === Order::STATUS_PAID) {
                // Staff confirming a payment the gateway never reported. Without
                // payment_status the expiry job and a late callback would still
                // treat the order as unpaid.
                $update['payment_status'] = PaymentStatus::PAID;
                $update['paid_at'] = $order->paid_at ?? now();
            }

            $order->update($update);

            // Idempotent, so an order whose stock the expiry job already
            // returned changes nothing here.
            if ($status === Order::STATUS_CANCELLED) {
                $this->releaser->release($order);
            }

            $this->activity->record(
                $order,
                'status',
                'Status: '.Order::statusLabel($from).' → '.Order::statusLabel($status).'.',
                auth('web')->id(),
            );

            DB::commit();
        } catch (Exception $e) {
            DB::rollBack();

            return $e;
        }

        $fresh = $order->fresh('items');
        $this->notifyStatusChange($fresh);

        return $fresh;
    }

    public function recordRefund(int $id, ?string $reference)
    {
        try {
            DB::beginTransaction();
            $order = $this->model->newQuery()->lockForUpdate()->findOrFail($id);

            if (! $order->owesRefund()) {
                throw new RuntimeException('Pengembalian dana hanya bisa dicatat untuk pesanan yang sudah dibayar, lalu dibatalkan, dan belum dicatat direfund.');
            }

            $order->update([
                'payment_status' => PaymentStatus::REFUNDED,
                'refunded_at' => now(),
                'refund_reference' => $reference,
            ]);

            $this->activity->record(
                $order,
                'refund',
                'Dana dikembalikan ke pelanggan'.($reference ? " (referensi: {$reference})" : '').'.',
                auth('web')->id(),
            );

            DB::commit();
        } catch (Exception $e) {
            DB::rollBack();

            return $e;
        }

        $fresh = $order->fresh('items');
        $this->notifier->orderRefunded($fresh);

        return $fresh;
    }

    private const DETAIL_LABELS = [
        'customer_name' => 'Nama',
        'customer_email' => 'Email',
        'customer_phone' => 'Telepon',
        'shipping_address' => 'Alamat',
        'shipping_city' => 'Kota',
        'shipping_province' => 'Provinsi',
        'shipping_postal_code' => 'Kode pos',
    ];

    /**
     * Corrects who and where an order goes to. Refused once a courier
     * shipment exists, since that shipment already carries the old address.
     */
    public function updateDetails(int $id, array $data)
    {
        try {
            DB::beginTransaction();
            $order = $this->model->newQuery()->lockForUpdate()->findOrFail($id);

            if (! in_array($order->status, [Order::STATUS_PENDING, Order::STATUS_PAID, Order::STATUS_PROCESSING], true)
                || filled($order->biteship_order_id)) {
                throw new RuntimeException('Data pesanan hanya bisa diubah sebelum pengiriman dibuat. Batalkan pengiriman di Biteship dulu jika alamatnya salah.');
            }

            $changes = [];

            foreach (self::DETAIL_LABELS as $field => $label) {
                $old = (string) ($order->{$field} ?? '');
                $new = (string) ($data[$field] ?? '');

                if ($old !== $new) {
                    $changes[] = "{$label}: \"{$old}\" → \"{$new}\"";
                }
            }

            if ($changes !== []) {
                $order->update(array_intersect_key($data, self::DETAIL_LABELS));
                $this->activity->record($order, 'details', 'Data pesanan diubah. '.implode('; ', $changes).'.', auth('web')->id());
            }

            DB::commit();

            return $order->fresh('items');
        } catch (Exception $e) {
            DB::rollBack();

            return $e;
        }
    }

    public function addNote(int $id, string $body)
    {
        try {
            $order = $this->model->newQuery()->findOrFail($id);

            return $this->activity->record($order, 'note', $body, auth('web')->id());
        } catch (Exception $e) {
            return $e;
        }
    }

    /**
     * Each transition happens once (the rules only move forward), so each
     * email here goes out once without needing a sent-log.
     */
    private function notifyStatusChange(Order $order): void
    {
        switch ($order->status) {
            case Order::STATUS_PAID:
                $this->notifier->paymentReceived($order);
                break;

            case Order::STATUS_SHIPPED:
                // Creating a Biteship shipment already sent this email. A
                // tracking number typed in by hand is only mailed once the
                // order is shipped, so it goes out here, not before.
                if (blank($order->biteship_order_id)) {
                    $this->notifier->orderShipped($order);
                }
                break;

            case Order::STATUS_COMPLETED:
                $this->notifier->orderDelivered($order);
                break;

            case Order::STATUS_CANCELLED:
                $this->notifier->orderCancelled($order);

                if ($order->paid_at !== null) {
                    $this->staffNotifier->refundNeeded($order);
                }
                break;
        }
    }

    /**
     * Records the courier/rate staff chose for this order after looking it
     * up via a ShippingProviderContract. This is a record-keeping write
     * only — it does not touch `total` or reopen payment, since the order
     * may already be paid for the amount charged at checkout.
     */
    public function updateShipping(int $id, array $data, ?array $activity = null)
    {
        try {
            DB::beginTransaction();
            $order = $this->model->findOrFail($id);
            $order->update($data);

            if ($activity !== null) {
                [$action, $description] = $activity;
                $this->activity->record($order, $action, $description, auth('web')->id());
            }

            DB::commit();

            return $order->fresh('items');
        } catch (Exception $e) {
            DB::rollBack();

            return $e;
        }
    }
}
