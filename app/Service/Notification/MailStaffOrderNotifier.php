<?php

namespace App\Service\Notification;

use App\Contract\Notification\StaffOrderNotifierContract;
use App\Mail\StaffLowStockMail;
use App\Mail\StaffOrderPaidMail;
use App\Mail\StaffRefundNeededMail;
use App\Mail\StaffShipmentProblemMail;
use App\Models\Order;
use App\Models\Product;
use App\Models\User;
use Illuminate\Mail\Mailable;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;
use Spatie\Permission\Exceptions\PermissionDoesNotExist;
use Throwable;

/**
 * Mails every staff account that can see orders, so the recipient list
 * follows the role setup instead of a separate address to keep in sync.
 */
class MailStaffOrderNotifier implements StaffOrderNotifierContract
{
    private const RECIPIENT_PERMISSION = 'order.view';

    public function orderPaid(Order $order): void
    {
        $this->deliver("order [{$order->order_number}]", new StaffOrderPaidMail($order->loadMissing('items')), 'staff order paid');
    }

    public function refundNeeded(Order $order): void
    {
        $this->deliver("order [{$order->order_number}]", new StaffRefundNeededMail($order->loadMissing('items')), 'staff refund needed');
    }

    public function shipmentProblem(Order $order, string $courierStatus): void
    {
        $this->deliver("order [{$order->order_number}]", new StaffShipmentProblemMail($order, $courierStatus), 'staff shipment problem');
    }

    public function lowStock(Product $product, int $threshold): void
    {
        $this->deliver("product [{$product->name}]", new StaffLowStockMail($product, $threshold), 'staff low stock');
    }

    private function deliver(string $subject, Mailable $mailable, string $label): void
    {
        try {
            $recipients = User::permission(self::RECIPIENT_PERMISSION)->pluck('email')->filter()->unique()->values();
        } catch (PermissionDoesNotExist) {
            $recipients = collect();
        } catch (Throwable $e) {
            Log::error("Failed to look up staff for [{$label}] mail on {$subject}: {$e->getMessage()}");

            return;
        }

        if ($recipients->isEmpty()) {
            Log::warning('No staff account has ['.self::RECIPIENT_PERMISSION."], so nobody was sent [{$label}] mail for {$subject}.");

            return;
        }

        try {
            Mail::to($recipients->all())->send($mailable);
        } catch (Throwable $e) {
            Log::error("Failed to send [{$label}] mail for {$subject}: {$e->getMessage()}");
        }
    }
}
