<?php

namespace App\Service\Payment;

use App\Contract\Payment\PaymentGatewayContract;
use App\Contract\Payment\PaymentStatus;
use App\Models\Order;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use RuntimeException;

/**
 * https://developers.doku.com — Checkout API (Non-SNAP signature scheme).
 *
 * DOKU signs whole requests (and expects us to verify whole inbound
 * notifications) with a component string built from headers + a body
 * digest, not a simple field-concatenation HMAC like the other
 * gateways. `notification_path` in config must match whatever path is
 * registered as the notification URL in DOKU's Back Office, since the
 * signature covers it and DOKU doesn't echo it back to us.
 */
class DokuService implements PaymentGatewayContract
{
    private const STATUS_MAP = [
        'PENDING' => PaymentStatus::PENDING,
        'SUCCESS' => PaymentStatus::PAID,
        'FAILED' => PaymentStatus::FAILED,
        'EXPIRED' => PaymentStatus::EXPIRED,
        'REFUNDED' => PaymentStatus::REFUNDED,
        'TIMEOUT' => PaymentStatus::EXPIRED,
        'REDIRECT' => PaymentStatus::PENDING,
    ];

    public function key(): string
    {
        return 'doku';
    }

    public function label(): string
    {
        return 'DOKU';
    }

    public function isConfigured(): bool
    {
        return filled(config('services.doku.client_id'))
            && filled(config('services.doku.secret_key'));
    }

    public function listChannels(): array
    {
        // The Checkout page lets the customer pick a channel themselves;
        // there's nothing for the merchant to pre-select.
        return [];
    }

    public function createTransaction(Order $order, int $amount, array $options = []): array
    {
        $invoiceNumber = $order->order_number;

        $lineItems = $order->items->map(fn ($item) => [
            'id' => (string) ($item->product_id ?? $item->id),
            'name' => $item->product_name,
            'price' => $item->unit_price,
            'quantity' => $item->quantity,
        ])->values()->all();

        $itemsTotal = array_sum(array_map(
            fn ($item) => $item['price'] * $item['quantity'],
            $lineItems
        ));

        $adjustment = $amount - $itemsTotal;

        if ($adjustment !== 0) {
            $lineItems[] = [
                'id' => 'ADMIN-FEE',
                'name' => 'Biaya Admin',
                'price' => $adjustment,
                'quantity' => 1,
            ];
        }

        $body = [
            'order' => [
                'amount' => $amount,
                'invoice_number' => $invoiceNumber,
                'currency' => 'IDR',
                'line_items' => $lineItems,
            ],
            'payment' => [
                'payment_due_date' => $options['expiry_minutes'] ?? 60,
            ],
            'customer' => [
                'name' => $order->customer_name,
                'email' => $order->customer_email,
                'phone' => $order->customer_phone,
            ],
        ];

        $response = $this->signedRequest('POST', '/checkout/v1/payment', $body);
        $data = $response->json();

        if (! $response->successful() || empty($data['payment']['url'])) {
            throw new RuntimeException('DOKU transaction creation failed: '.($data['message'] ?? $response->body()));
        }

        return [
            'reference' => $invoiceNumber,
            'redirect_url' => $data['payment']['url'],
            'token' => $data['payment']['token_id'] ?? null,
            'expires_at' => $data['payment']['expired_date'] ?? null,
            'raw' => $data,
        ];
    }

    public function verifyNotification(string $rawBody, array $headers = []): bool
    {
        $clientId = $headers['client-id'] ?? null;
        $requestId = $headers['request-id'] ?? null;
        $timestamp = $headers['request-timestamp'] ?? null;
        $signature = $headers['signature'] ?? null;

        if (! $clientId || ! $requestId || ! $timestamp || ! $signature) {
            return false;
        }

        if (! hash_equals((string) config('services.doku.client_id'), $clientId)) {
            return false;
        }

        $digest = base64_encode(hash('sha256', $rawBody, true));

        $component = "Client-Id:{$clientId}\n".
            "Request-Id:{$requestId}\n".
            "Request-Timestamp:{$timestamp}\n".
            'Request-Target:'.config('services.doku.notification_path')."\n".
            "Digest:{$digest}";

        $expected = 'HMACSHA256='.base64_encode(
            hash_hmac('sha256', $component, config('services.doku.secret_key'), true)
        );

        return hash_equals($expected, $signature);
    }

    public function parseNotification(array $payload): array
    {
        $status = $payload['transaction']['status'] ?? '';

        return [
            'reference' => $payload['order']['invoice_number'],
            'status' => self::STATUS_MAP[$status] ?? PaymentStatus::PENDING,
            'amount' => (int) ($payload['order']['amount'] ?? 0),
            'paid_at' => $payload['transaction']['date'] ?? null,
            'raw' => $payload,
        ];
    }

    public function getStatus(string $reference): array
    {
        $response = $this->signedRequest('GET', '/orders/v1/status/'.$reference);
        $data = $response->json();

        if (! $response->successful() || empty($data['order']['invoice_number'])) {
            throw new RuntimeException('DOKU status lookup failed: '.($data['message'] ?? $response->body()));
        }

        return $this->parseNotification($data);
    }

    private function signedRequest(string $method, string $path, ?array $body = null)
    {
        $clientId = config('services.doku.client_id');
        $secretKey = config('services.doku.secret_key');
        $requestId = (string) Str::uuid();
        $timestamp = gmdate('Y-m-d\TH:i:s\Z');

        $component = "Client-Id:{$clientId}\nRequest-Id:{$requestId}\nRequest-Timestamp:{$timestamp}\nRequest-Target:{$path}";

        $json = null;

        if ($body !== null) {
            $json = json_encode($body);
            $digest = base64_encode(hash('sha256', $json, true));
            $component .= "\nDigest:{$digest}";
        }

        $signature = 'HMACSHA256='.base64_encode(hash_hmac('sha256', $component, $secretKey, true));

        $headers = [
            'Client-Id' => $clientId,
            'Request-Id' => $requestId,
            'Request-Timestamp' => $timestamp,
            'Signature' => $signature,
        ];

        $request = Http::withHeaders($headers)->acceptJson();
        $url = $this->baseUrl().$path;

        if ($json !== null) {
            return $request->withBody($json, 'application/json')->post($url);
        }

        return $request->get($url);
    }

    private function baseUrl(): string
    {
        return config('services.doku.sandbox', true)
            ? 'https://api-sandbox.doku.com'
            : 'https://api.doku.com';
    }
}
