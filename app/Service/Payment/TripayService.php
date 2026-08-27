<?php

namespace App\Service\Payment;

use App\Contract\Payment\PaymentGatewayContract;
use App\Contract\Payment\PaymentStatus;
use App\Models\Order;
use Illuminate\Support\Facades\Http;
use InvalidArgumentException;
use RuntimeException;

/**
 * https://tripay.co.id/developer
 *
 * Closed-payment (fixed amount) integration. `amount` is validated by
 * Tripay against the sum of `order_items`, so when the caller's amount
 * includes an admin fee that isn't one of the order's line items, we
 * append a synthetic "Biaya Admin" item to reconcile the two totals.
 */
class TripayService implements PaymentGatewayContract
{
    private const STATUS_MAP = [
        'PAID' => PaymentStatus::PAID,
        'UNPAID' => PaymentStatus::PENDING,
        'FAILED' => PaymentStatus::FAILED,
        'EXPIRED' => PaymentStatus::EXPIRED,
        'REFUND' => PaymentStatus::REFUNDED,
    ];

    public function key(): string
    {
        return 'tripay';
    }

    public function label(): string
    {
        return 'Tripay';
    }

    public function isConfigured(): bool
    {
        return filled(config('services.tripay.merchant_code'))
            && filled(config('services.tripay.api_key'))
            && filled(config('services.tripay.private_key'));
    }

    public function listChannels(): array
    {
        $response = Http::withToken(config('services.tripay.api_key'))
            ->acceptJson()
            ->get($this->baseUrl().'/merchant/payment-channel');

        $body = $response->json();

        if (! $response->successful() || empty($body['success'])) {
            throw new RuntimeException('Tripay channel list lookup failed: '.($body['message'] ?? $response->body()));
        }

        return collect($body['data'] ?? [])
            ->filter(fn ($channel) => $channel['active'] ?? true)
            ->map(fn ($channel) => [
                'code' => $channel['code'],
                'name' => $channel['name'],
                // total_fee is the channel's real, full processing cost
                // (fee_merchant + fee_customer combined) — the honest
                // number for "what does this transaction actually cost",
                // independent of how Tripay's own dashboard happens to
                // split merchant/customer fees for this account.
                'fee_flat' => (int) ($channel['total_fee']['flat'] ?? 0),
                'fee_percent' => (float) ($channel['total_fee']['percent'] ?? 0),
            ])
            ->values()
            ->all();
    }

    public function createTransaction(Order $order, int $amount, array $options = []): array
    {
        if (empty($options['method'])) {
            throw new InvalidArgumentException('Tripay requires options[method] (payment channel code).');
        }

        $merchantCode = config('services.tripay.merchant_code');
        $privateKey = config('services.tripay.private_key');
        $merchantRef = $order->order_number;

        $orderItems = $order->items->map(fn ($item) => [
            'sku' => (string) ($item->product_id ?? $item->id),
            'name' => $item->product_name,
            'price' => $item->unit_price,
            'quantity' => $item->quantity,
        ])->values()->all();

        $itemsTotal = array_sum(array_map(
            fn ($item) => $item['price'] * $item['quantity'],
            $orderItems
        ));

        $adjustment = $amount - $itemsTotal;

        if ($adjustment !== 0) {
            $orderItems[] = [
                'sku' => 'ADMIN-FEE',
                'name' => 'Biaya Admin',
                'price' => $adjustment,
                'quantity' => 1,
            ];
        }

        $payload = [
            'method' => $options['method'],
            'merchant_ref' => $merchantRef,
            'amount' => $amount,
            'customer_name' => $order->customer_name,
            'customer_email' => $order->customer_email,
            'customer_phone' => $order->customer_phone,
            'order_items' => $orderItems,
            'signature' => hash_hmac('sha256', $merchantCode.$merchantRef.$amount, $privateKey),
        ];

        if (! empty($options['callback_url'])) {
            $payload['callback_url'] = $options['callback_url'];
        }

        if (! empty($options['return_url'])) {
            $payload['return_url'] = $options['return_url'];
        }

        if (! empty($options['expired_time'])) {
            $payload['expired_time'] = $options['expired_time'];
        }

        $response = Http::withToken(config('services.tripay.api_key'))
            ->acceptJson()
            ->post($this->baseUrl().'/transaction/create', $payload);

        $body = $response->json();

        if (! $response->successful() || empty($body['success'])) {
            throw new RuntimeException('Tripay transaction creation failed: '.($body['message'] ?? $response->body()));
        }

        $data = $body['data'];

        return [
            'reference' => $data['reference'],
            'redirect_url' => $data['checkout_url'] ?? $data['pay_url'] ?? null,
            'token' => null,
            'expires_at' => isset($data['expired_time']) ? date('c', (int) $data['expired_time']) : null,
            'raw' => $data,
        ];
    }

    public function verifyNotification(string $rawBody, array $headers = []): bool
    {
        $signature = $headers['x-callback-signature'] ?? null;

        if (! $signature) {
            return false;
        }

        $expected = hash_hmac('sha256', $rawBody, config('services.tripay.private_key'));

        return hash_equals($expected, $signature);
    }

    public function parseNotification(array $payload): array
    {
        return [
            'reference' => $payload['reference'],
            'status' => self::STATUS_MAP[$payload['status']] ?? PaymentStatus::PENDING,
            'amount' => (int) ($payload['total_amount'] ?? 0),
            'paid_at' => ! empty($payload['paid_at']) ? date('c', (int) $payload['paid_at']) : null,
            'raw' => $payload,
        ];
    }

    public function getStatus(string $reference): array
    {
        $response = Http::withToken(config('services.tripay.api_key'))
            ->acceptJson()
            ->get($this->baseUrl().'/transaction/detail', ['reference' => $reference]);

        $body = $response->json();

        if (! $response->successful() || empty($body['success'])) {
            throw new RuntimeException('Tripay transaction detail lookup failed: '.($body['message'] ?? $response->body()));
        }

        $data = $body['data'];

        return [
            'reference' => $data['reference'],
            'status' => self::STATUS_MAP[$data['status']] ?? PaymentStatus::PENDING,
            'amount' => (int) $data['amount'],
            'paid_at' => ! empty($data['paid_at']) ? date('c', (int) $data['paid_at']) : null,
            'raw' => $data,
        ];
    }

    private function baseUrl(): string
    {
        return config('services.tripay.sandbox', true)
            ? 'https://tripay.co.id/api-sandbox'
            : 'https://tripay.co.id/api';
    }
}
