<?php

namespace App\Mail;

use App\Models\Order;
use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Queue\SerializesModels;

class StaffShipmentProblemMail extends Mailable
{
    use Queueable, SerializesModels;

    public function __construct(public Order $order, public string $courierStatus) {}

    public function envelope(): Envelope
    {
        return new Envelope(
            subject: '[Masalah Pengiriman] '.$this->courierStatus.' · '.$this->order->order_number,
        );
    }

    public function content(): Content
    {
        return new Content(
            markdown: 'mail.staff.shipment_problem',
            with: [
                'order' => $this->order,
                'courierStatus' => $this->courierStatus,
                'backofficeUrl' => route('backoffice.order.show', $this->order->id),
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
