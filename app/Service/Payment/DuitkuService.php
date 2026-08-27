<?php

namespace App\Service\Payment;

use App\Contract\Payment\PaymentGatewayContract;
use App\Contract\Payment\PaymentStatus;
use App\Models\Order;
use Illuminate\Support\Facades\Http;
use InvalidArgumentException;
use RuntimeException;

/**
 * https://docs.duitku.com — Direct API (v2/inquiry).
 *
 * Duitku's own status-check endpoint (transactionStatus) is keyed by
 * *our* merchantOrderId, not by Duitku's internal `reference` value. So
 * unlike the other gateways, this implementation normalizes `reference`
 * to the order_number (merchantOrderId) throughout — createTransaction,
 * parseNotification, and getStatus all key off it — rather than
 * Duitku's own transaction reference, which is kept only in `raw`.
 */
class DuitkuService implements PaymentGatewayContract
{
    /**
     * Duitku's channel list depends on the payment amount (its fees can
     * be percentage-based), but a channel picker just needs the list of
     * codes/names, not a live fee quote — so we call it with this fixed,
     * representative amount rather than requiring a real order.
     */
    private const CHANNEL_LIST_SAMPLE_AMOUNT = 100000;

    public function key(): string
    {
        return 'duitku';
    }

    public function label(): string
    {
        return 'Duitku';
    }

    public function isConfigured(): bool
    {
        return filled(config('services.duitku.merchant_code'))
            && filled(config('services.duitku.api_key'));
    }

    public function listChannels(): array
    {
        $merchantCode = config('services.duitku.merchant_code');
        $apiKey = config('services.duitku.api_key');
        $datetime = now()->format('Y-m-d H:i:s');

        $response = Http::acceptJson()->post($this->baseUrl().'/webapi/api/merchant/paymentmethod/getpaymentmethod', [
            'merchantCode' => $merchantCode,
            'amount' => self::CHANNEL_LIST_SAMPLE_AMOUNT,
            'datetime' => $datetime,
            'signature' => hash('sha256', $merchantCode.self::CHANNEL_LIST_SAMPLE_AMOUNT.$datetime.$apiKey),
        ]);

        $body = $response->json();

        if (! $response->successful() || ! isset($body['paymentFee'])) {
            throw new RuntimeException('Duitku channel list lookup failed: '.($body['responseMessage'] ?? $response->body()));
        }

        return collect($body['paymentFee'])
            ->map(fn ($channel) => [
                'code' => $channel['paymentMethod'],
                'name' => $channel['paymentName'],
                // Duitku returns one combined fee for the queried sample
                // amount rather than a separate flat/percent breakdown,
                // so this is only an approximation near that amount, not
                // a precise formula — surfaced as flat with no percent
                // component rather than guessing a split.
                'fee_flat' => (int) ($channel['totalFee'] ?? 0),
                'fee_percent' => null,
            ])
            ->values()
            ->all();
    }

    public function createTransaction(Order $order, int $amount, array $options = []): array
    {
        if (empty($options['method'])) {
            throw new InvalidArgumentException('Duitku requires options[method] (payment method code).');
        }

        $merchantCode = config('services.duitku.merchant_code');
        $apiKey = config('services.duitku.api_key');
        $merchantOrderId = $order->order_number;

        $itemDetails = $order->items->map(fn ($item) => [
            'name' => $item->product_name,
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
                'name' => 'Biaya Admin',
                'price' => $adjustment,
                'quantity' => 1,
            ];
        }

        $payload = [
            'merchantCode' => $merchantCode,
            'paymentAmount' => $amount,
            'paymentMethod' => $options['method'],
            'merchantOrderId' => $merchantOrderId,
            'productDetails' => "Pesanan {$merchantOrderId}",
            'email' => $order->customer_email,
            'phoneNumber' => $order->customer_phone,
            'itemDetails' => $itemDetails,
            'customerDetail' => [
                'firstName' => $order->customer_name,
                'email' => $order->customer_email,
                'phoneNumber' => $order->customer_phone,
            ],
            'signature' => md5($merchantCode.$merchantOrderId.$amount.$apiKey),
        ];

        if (! empty($options['callback_url'])) {
            $payload['callbackUrl'] = $options['callback_url'];
        }

        if (! empty($options['return_url'])) {
            $payload['returnUrl'] = $options['return_url'];
        }

        if (! empty($options['expiry_minutes'])) {
            $payload['expiryPeriod'] = $options['expiry_minutes'];
        }

        $response = Http::acceptJson()
            ->post($this->baseUrl().'/webapi/api/merchant/v2/inquiry', $payload);

        $body = $response->json();

        if (! $response->successful() || ($body['statusCode'] ?? null) !== '00') {
            throw new RuntimeException('Duitku transaction creation failed: '.($body['statusMessage'] ?? $response->body()));
        }

        return [
            'reference' => $merchantOrderId,
            'redirect_url' => $body['paymentUrl'] ?? null,
            'token' => null,
            'expires_at' => null,
            'raw' => $body,
        ];
    }

    public function verifyNotification(string $rawBody, array $headers = []): bool
    {
        parse_str($rawBody, $fields);

        if (empty($fields['signature']) || ! isset($fields['merchantCode'], $fields['amount'], $fields['merchantOrderId'])) {
            return false;
        }

        $expected = md5($fields['merchantCode'].$fields['amount'].$fields['merchantOrderId'].config('services.duitku.api_key'));

        return hash_equals($expected, $fields['signature']);
    }

    public function parseNotification(array $payload): array
    {
        $resultCode = (string) ($payload['resultCode'] ?? '');

        return [
            'reference' => $payload['merchantOrderId'],
            'status' => match ($resultCode) {
                '00' => PaymentStatus::PAID,
                '01' => PaymentStatus::PENDING,
                default => PaymentStatus::FAILED,
            },
            'amount' => (int) ($payload['amount'] ?? 0),
            'paid_at' => null,
            'raw' => $payload,
        ];
    }

    public function getStatus(string $reference): array
    {
        $merchantCode = config('services.duitku.merchant_code');
        $apiKey = config('services.duitku.api_key');

        $response = Http::acceptJson()->post($this->baseUrl().'/webapi/api/merchant/transactionStatus', [
            'merchantCode' => $merchantCode,
            'merchantOrderId' => $reference,
            'signature' => md5($merchantCode.$reference.$apiKey),
        ]);

        $body = $response->json();

        if (! $response->successful() || ! isset($body['statusCode'])) {
            throw new RuntimeException('Duitku status lookup failed: '.($body['statusMessage'] ?? $response->body()));
        }

        return [
            'reference' => $reference,
            'status' => match ($body['statusCode']) {
                '00' => PaymentStatus::PAID,
                '01' => PaymentStatus::PENDING,
                default => PaymentStatus::FAILED,
            },
            'amount' => (int) ($body['amount'] ?? 0),
            'paid_at' => null,
            'raw' => $body,
        ];
    }

    private function baseUrl(): string
    {
        return config('services.duitku.sandbox', true)
            ? 'https://sandbox.duitku.com'
            : 'https://passport.duitku.com';
    }
}
