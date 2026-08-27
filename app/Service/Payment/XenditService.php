<?php

namespace App\Service\Payment;

use App\Contract\Payment\PaymentGatewayContract;
use App\Contract\Payment\PaymentStatus;
use App\Models\Order;
use Illuminate\Support\Facades\Http;
use RuntimeException;

/**
 * https://developers.xendit.co — Invoice API.
 *
 * Xendit has a single API host for both test and live mode; sandbox vs.
 * production is determined entirely by which secret key is configured,
 * not by a separate base URL.
 */
class XenditService implements PaymentGatewayContract
{
    private const STATUS_MAP = [
        'PENDING' => PaymentStatus::PENDING,
        'PAID' => PaymentStatus::PAID,
        'SETTLED' => PaymentStatus::PAID,
        'EXPIRED' => PaymentStatus::EXPIRED,
    ];

    private const BASE_URL = 'https://api.xendit.co';

    public function key(): string
    {
        return 'xendit';
    }

    public function label(): string
    {
        return 'Xendit';
    }

    public function isConfigured(): bool
    {
        return filled(config('services.xendit.secret_key'));
    }

    public function listChannels(): array
    {
        // The Invoice page lets the customer pick a channel themselves;
        // there's nothing for the merchant to pre-select.
        return [];
    }

    public function createTransaction(Order $order, int $amount, array $options = []): array
    {
        $items = $order->items->map(fn ($item) => [
            'name' => $item->product_name,
            'price' => $item->unit_price,
            'quantity' => $item->quantity,
        ])->values()->all();

        $itemsTotal = array_sum(array_map(
            fn ($item) => $item['price'] * $item['quantity'],
            $items
        ));

        $adjustment = $amount - $itemsTotal;

        $payload = [
            'external_id' => $order->order_number,
            'amount' => $amount,
            'payer_email' => $order->customer_email,
            'description' => "Pembayaran pesanan {$order->order_number}",
            'items' => $items,
        ];

        if ($adjustment > 0) {
            $payload['fees'] = [
                ['type' => 'Biaya Admin', 'value' => $adjustment],
            ];
        }

        if (! empty($options['return_url'])) {
            $payload['success_redirect_url'] = $options['return_url'];
        }

        $response = Http::withBasicAuth(config('services.xendit.secret_key'), '')
            ->acceptJson()
            ->post(self::BASE_URL.'/v2/invoices', $payload);

        $body = $response->json();

        if (! $response->successful() || empty($body['id'])) {
            throw new RuntimeException('Xendit invoice creation failed: '.($body['message'] ?? $response->body()));
        }

        return [
            'reference' => $body['id'],
            'redirect_url' => $body['invoice_url'] ?? null,
            'token' => null,
            'expires_at' => $body['expiry_date'] ?? null,
            'raw' => $body,
        ];
    }

    public function verifyNotification(string $rawBody, array $headers = []): bool
    {
        $token = $headers['x-callback-token'] ?? null;

        if (! $token) {
            return false;
        }

        return hash_equals((string) config('services.xendit.callback_token'), $token);
    }

    public function parseNotification(array $payload): array
    {
        return [
            'reference' => $payload['id'],
            'status' => self::STATUS_MAP[$payload['status'] ?? ''] ?? PaymentStatus::PENDING,
            'amount' => (int) round((float) ($payload['paid_amount'] ?? $payload['amount'] ?? 0)),
            'paid_at' => $payload['paid_at'] ?? null,
            'raw' => $payload,
        ];
    }

    public function getStatus(string $reference): array
    {
        $response = Http::withBasicAuth(config('services.xendit.secret_key'), '')
            ->acceptJson()
            ->get(self::BASE_URL.'/v2/invoices/'.$reference);

        $body = $response->json();

        if (! $response->successful() || empty($body['id'])) {
            throw new RuntimeException('Xendit invoice lookup failed: '.($body['message'] ?? $response->body()));
        }

        return $this->parseNotification($body);
    }
}
