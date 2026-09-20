<?php

namespace App\Service\Notification;

use App\Contract\Notification\OrderNotifierContract;
use App\Mail\OrderPlacedMail;
use App\Mail\OrderShippedMail;
use App\Mail\PaymentReceivedMail;
use App\Models\Order;
use Illuminate\Mail\Mailable;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;
use Throwable;

/**
 * Sends order mail over whatever MAIL_MAILER is configured.
 *
 * Delivery is synchronous on purpose: QUEUE_CONNECTION defaults to
 * `database`, so queueing here would mean nothing is ever delivered
 * until someone runs a worker — a silent failure worse than a slow
 * response. Move these to queued mailables once a worker is running.
 */
class MailOrderNotifier implements OrderNotifierContract
{
    public function orderPlaced(Order $order): void
    {
        $this->deliver($order, new OrderPlacedMail($order->loadMissing('items')), 'order placed');
    }

    public function paymentReceived(Order $order): void
    {
        $this->deliver($order, new PaymentReceivedMail($order->loadMissing('items')), 'payment received');
    }

    public function orderShipped(Order $order): void
    {
        $this->deliver($order, new OrderShippedMail($order->loadMissing('items')), 'order shipped');
    }

    private function deliver(Order $order, Mailable $mailable, string $label): void
    {
        if (blank($order->customer_email)) {
            return;
        }

        try {
            Mail::to($order->customer_email)->send($mailable);
        } catch (Throwable $e) {
            // Called mid-checkout and from the payment callback: a mail
            // failure must never roll back or reject the thing that
            // actually happened. Log loudly instead.
            Log::error("Failed to send [{$label}] mail for order [{$order->order_number}]: {$e->getMessage()}");
        }
    }
}
