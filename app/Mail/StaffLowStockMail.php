<?php

namespace App\Mail;

use App\Models\Product;
use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Queue\SerializesModels;

class StaffLowStockMail extends Mailable
{
    use Queueable, SerializesModels;

    public function __construct(public Product $product, public int $threshold) {}

    public function envelope(): Envelope
    {
        return new Envelope(
            subject: '[Stok Menipis] '.$this->product->name.' · sisa '.$this->product->stock,
        );
    }

    public function content(): Content
    {
        return new Content(
            markdown: 'mail.staff.low_stock',
            with: [
                'product' => $this->product,
                'threshold' => $this->threshold,
                'backofficeUrl' => route('backoffice.product.show', $this->product->id),
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
