<?php

namespace App\Service\Payment;

use App\Contract\Payment\PaymentGatewayContract;
use App\Contract\Payment\PaymentStatus;
use App\Models\Order;
use Illuminate\Support\Facades\Http;
use RuntimeException;

/**
 * https://docs.midtrans.com — Snap API (payment page) + Core API (status).
 *
 * Like Tripay, item_details is validated against gross_amount, so an
 * admin fee that isn't one of the order's line items is appended as a
 * synthetic item to keep the two in sync.
 */
class MidtransService implements PaymentGatewayContract
{
    private const STATUS_MAP = [
        'capture' => PaymentStatus::PAID,
        'settlement' => PaymentStatus::PAID,
        'pending' => PaymentStatus::PENDING,
        'deny' => PaymentStatus::FAILED,
        'cancel' => PaymentStatus::CANCELLED,
        'expire' => PaymentStatus::EXPIRED,
        'refund' => PaymentStatus::REFUNDED,
        'partial_refund' => PaymentStatus::REFUNDED,
    ];

    public function key(): string
    {
        return 'midtrans';
    }

    public function label(): string
    {
        return 'Midtrans';
    }

    public function isConfigured(): bool
    {
        return filled(config('services.midtrans.server_key'));
    }

    public function listChannels(): array
    {
        // Snap's hosted payment page lets the customer pick a channel
        // themselves; there's nothing for the merchant to pre-select.
        return [];
    }

    public function createTransaction(Order $order, int $amount, array $options = []): array
    {
        $orderId = $order->order_number;

        $itemDetails = $order->items->map(fn ($item) => [
            'id' => (string) ($item->product_id ?? $item->id),
            'name' => mb_substr($item->product_name, 0, 50),
            'price' => $item->unit_price,
            'quantity' => $item->quantity,
        ])->values()->all();

        $itemsTotal = array_sum(array_map(
            fn ($item) => $item['price'] * $item['quantity'],
            $itemDetails
        ));

        $adjustment = $amount - $itemsTotal;

        if ($adjustment !== 0) {
            $itemDetails[] = [
                'id' => 'ADMIN-FEE',
                'name' => 'Biaya Admin',
                'price' => $adjustment,
                'quantity' => 1,
            ];
        }

        $payload = [
            'transaction_details' => [
                'order_id' => $orderId,
                'gross_amount' => $amount,
            ],
            'item_details' => $itemDetails,
            'customer_details' => [
                'first_name' => $order->customer_name,
                'email' => $order->customer_email,
                'phone' => $order->customer_phone,
            ],
        ];

        $request = Http::withBasicAuth(config('services.midtrans.server_key'), '')
            ->acceptJson();

        if (! empty($options['callback_url'])) {
            $request = $request->withHeaders(['X-Override-Notification' => $options['callback_url']]);
        }

        $response = $request->post($this->snapBaseUrl().'/transactions', $payload);

        $body = $response->json();

        if (! $response->successful() || empty($body['token'])) {
            throw new RuntimeException('Midtrans transaction creation failed: '.($body['error_messages'][0] ?? $response->body()));
        }

        return [
            'reference' => $orderId,
            'redirect_url' => $body['redirect_url'] ?? null,
            'token' => $body['token'],
            'expires_at' => null,
            'raw' => $body,
        ];
    }

    public function verifyNotification(string $rawBody, array $headers = []): bool
    {
        $payload = json_decode($rawBody, true);

        if (! is_array($payload) || empty($payload['signature_key'])) {
            return false;
        }

        $expected = hash('sha512',
            ($payload['order_id'] ?? '').
            ($payload['status_code'] ?? '').
            ($payload['gross_amount'] ?? '').
            config('services.midtrans.server_key')
        );

        return hash_equals($expected, $payload['signature_key']);
    }

    public function parseNotification(array $payload): array
    {
        return [
            'reference' => $payload['order_id'],
            'status' => $this->mapStatus($payload['transaction_status'] ?? '', $payload['fraud_status'] ?? null),
            'amount' => (int) round((float) ($payload['gross_amount'] ?? 0)),
            'paid_at' => $payload['settlement_time'] ?? $payload['transaction_time'] ?? null,
            'raw' => $payload,
        ];
    }

    public function getStatus(string $reference): array
    {
        $response = Http::withBasicAuth(config('services.midtrans.server_key'), '')
            ->acceptJson()
            ->get($this->coreApiBaseUrl().'/v2/'.$reference.'/status');

        $body = $response->json();

        if (! $response->successful() || empty($body['transaction_status'])) {
            throw new RuntimeException('Midtrans status lookup failed: '.($body['status_message'] ?? $response->body()));
        }

        return $this->parseNotification($body);
    }

    private function mapStatus(string $transactionStatus, ?string $fraudStatus): string
    {
        if ($transactionStatus === 'capture') {
            return $fraudStatus === 'accept' ? PaymentStatus::PAID : PaymentStatus::PENDING;
        }

        return self::STATUS_MAP[$transactionStatus] ?? PaymentStatus::PENDING;
    }

    private function snapBaseUrl(): string
    {
        return config('services.midtrans.sandbox', true)
            ? 'https://app.sandbox.midtrans.com/snap/v1'
            : 'https://app.midtrans.com/snap/v1';
    }

    private function coreApiBaseUrl(): string
    {
        return config('services.midtrans.sandbox', true)
            ? 'https://api.sandbox.midtrans.com'
            : 'https://api.midtrans.com';
    }
}
