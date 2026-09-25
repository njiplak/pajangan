<?php

namespace App\Service\Order;

use App\Contract\Notification\OrderNotifierContract;
use App\Contract\Payment\PaymentStatus;
use App\Models\Customer;
use App\Models\Order;
use App\Models\StockMovement;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Orders staff take outside the storefront (chat, phone, in person). They
 * draw stock exactly like a checkout, so the catalogue never sells units
 * already promised over chat.
 */
class ManualOrderService
{
    public const PAYMENT_GATEWAY = 'manual';

    public function __construct(
        private readonly StockDrawPlanner $planner,
        private readonly OrderActivityLog $activity,
        private readonly OrderNotifierContract $notifier,
    ) {}

    /**
     * @throws ValidationException when the stock isn't there
     */
    public function create(array $data, ?int $userId): Order
    {
        $quantities = [];

        foreach ($data['items'] as $item) {
            $quantities[(int) $item['product_id']] = (int) $item['quantity'];
        }

        $paid = (bool) $data['paid'];

        $order = DB::transaction(function () use ($data, $quantities, $paid, $userId) {
            try {
                $plan = $this->planner->plan($quantities);
            } catch (StockDrawException $e) {
                throw ValidationException::withMessages(['items' => $this->message($e)]);
            }

            $email = filled($data['customer_email'] ?? null) ? $data['customer_email'] : null;
            $shippingCost = (int) $data['shipping_cost'];

            $order = Order::create([
                'customer_id' => $email
                    ? Customer::query()->whereRaw('LOWER(email) = ?', [mb_strtolower($email)])->value('id')
                    : null,
                'order_number' => Order::generateNumber(),
                'source' => Order::SOURCE_MANUAL,
                'customer_name' => $data['customer_name'],
                'customer_email' => $email,
                'customer_phone' => $data['customer_phone'],
                'shipping_address' => $data['shipping_address'],
                'shipping_city' => $data['shipping_city'],
                'shipping_province' => $data['shipping_province'],
                'shipping_postal_code' => $data['shipping_postal_code'] ?? null,
                'notes' => $data['notes'] ?? null,
                'stock_draw' => $plan['draw'],
                'status' => $paid ? Order::STATUS_PAID : Order::STATUS_PENDING,
                'subtotal' => $plan['subtotal'],
                'shipping_cost' => $shippingCost,
                'shipping_discount' => 0,
                'courier_name' => $data['courier_name'] ?? null,
                'total' => $plan['subtotal'] + $shippingCost,
                // No gateway transaction exists, so the expiry job cancels an
                // unpaid one on its hold window without asking any gateway.
                'payment_gateway' => self::PAYMENT_GATEWAY,
                'payment_channel' => $data['payment_note'] ?? null,
                'payment_status' => $paid ? PaymentStatus::PAID : PaymentStatus::PENDING,
                'paid_at' => $paid ? now() : null,
            ]);

            $this->planner->apply($plan, $order, StockMovement::REASON_MANUAL_ORDER, $userId);

            $this->activity->record(
                $order,
                'created',
                'Pesanan dicatat manual oleh staf'
                    .($paid ? ', sudah dibayar'.(filled($data['payment_note'] ?? null) ? " ({$data['payment_note']})" : '') : ', menunggu pembayaran')
                    .'.',
                $userId,
            );

            return $order;
        });

        $fresh = $order->fresh('items');

        if ($paid) {
            $this->notifier->paymentReceived($fresh);
        } else {
            $this->notifier->orderPlaced($fresh);
        }

        return $fresh;
    }

    private function message(StockDrawException $e): string
    {
        return match ($e->reason) {
            StockDrawException::BUNDLE_CHANGED => 'Isi salah satu paket baru saja berubah. Muat ulang halaman lalu coba lagi.',
            StockDrawException::UNAVAILABLE => ($e->productName ?? 'Salah satu produk').' sedang tidak aktif dan tidak bisa dipesan.',
            StockDrawException::LINE_SHORT => "Stok {$e->productName} tinggal {$e->available}.",
            default => 'Stok '.($e->productName ?? 'produk').' tidak cukup untuk semua item di pesanan ini.',
        };
    }
}
