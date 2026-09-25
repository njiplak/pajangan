<?php

namespace App\Mail;

use App\Models\Order;
use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\URL;

class OrderShipmentCancelledMail extends Mailable
{
    use Queueable, SerializesModels;

    public function __construct(public Order $order, public ?string $voidTrackingNumber) {}

    public function envelope(): Envelope
    {
        return new Envelope(
            subject: 'Pengiriman Diatur Ulang · '.$this->order->order_number,
        );
    }

    public function content(): Content
    {
        return new Content(
            markdown: 'mail.orders.shipment_cancelled',
            with: [
                'order' => $this->order,
                'voidTrackingNumber' => $this->voidTrackingNumber,
                'orderUrl' => URL::signedRoute('order.show', ['order' => $this->order->order_number]),
            ],
        );
    }

    /**
     * @return array<int, \Illuminate\Mail\Mailables\Attachment>
     */
    public function attachments(): array
    {
        return [];
    }
}
