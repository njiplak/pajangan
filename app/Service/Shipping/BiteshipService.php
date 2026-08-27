<?php

namespace App\Service\Shipping;

use App\Contract\Shipping\ShippingProviderContract;
use Illuminate\Support\Facades\Http;
use RuntimeException;

/**
 * https://biteship.com/en/docs/api — Maps + Rates API.
 *
 * Auth is a single "authorization" header carrying the raw API key (no
 * "Bearer" prefix); the key's biteship_live./biteship_test. prefix
 * determines live vs. test mode, there is no separate sandbox host.
 */
class BiteshipService implements ShippingProviderContract
{
    public function key(): string
    {
        return 'biteship';
    }

    public function label(): string
    {
        return 'Biteship';
    }

    public function isConfigured(): bool
    {
        return filled(config('services.biteship.api_key'))
            && filled(config('services.biteship.origin_area_id'));
    }

    public function searchAreas(string $query): array
    {
        $response = $this->client()->get('/v1/maps/areas', [
            'countries' => 'ID',
            'input' => $query,
            'type' => 'single',
        ]);

        $body = $response->json();

        if (! $response->successful() || empty($body['success'])) {
            throw new RuntimeException('Biteship area search failed: '.($body['error'] ?? $response->body()));
        }

        return collect($body['areas'] ?? [])
            ->map(fn (array $area) => [
                'id' => (string) $area['id'],
                'name' => $area['name'],
                'postal_code' => $area['postal_code'] ?? null,
            ])
            ->values()
            ->all();
    }

    public function quoteRates(array $request): array
    {
        if (! $this->isConfigured()) {
            throw new RuntimeException('Biteship is not configured.');
        }

        $response = $this->client()->post('/v1/rates/couriers', [
            'origin_area_id' => config('services.biteship.origin_area_id'),
            'destination_area_id' => $request['destination_area_id'],
            'couriers' => config('services.biteship.couriers'),
            'items' => [[
                'name' => 'Barang',
                'value' => $request['item_value'],
                'quantity' => 1,
                'weight' => $request['weight_gram'],
            ]],
        ]);

        $body = $response->json();

        if (! $response->successful() || empty($body['success'])) {
            throw new RuntimeException('Biteship rate quote failed: '.($body['error'] ?? $response->body()));
        }

        return collect($body['pricing'] ?? [])
            ->map(fn (array $rate) => [
                'courier_code' => $rate['courier_code'],
                'courier_name' => $rate['courier_name'],
                'courier_service_code' => $rate['courier_service_code'],
                'courier_service_name' => $rate['courier_service_name'],
                'price' => (int) $rate['price'],
                'duration' => $rate['duration'] ?? null,
                'collection_methods' => $rate['available_collection_method'] ?? [],
            ])
            ->values()
            ->all();
    }

    public function createShipment(array $request): array
    {
        if (! $this->isConfigured()
            || blank(config('services.biteship.sender_name'))
            || blank(config('services.biteship.sender_phone'))
            || blank(config('services.biteship.sender_address'))
        ) {
            throw new RuntimeException('Biteship sender identity is not configured.');
        }

        $response = $this->client()->post('/v1/orders', [
            'origin_contact_name' => config('services.biteship.sender_name'),
            'origin_contact_phone' => config('services.biteship.sender_phone'),
            'origin_address' => config('services.biteship.sender_address'),
            'origin_area_id' => config('services.biteship.origin_area_id'),
            'destination_contact_name' => $request['destination_contact_name'],
            'destination_contact_phone' => $request['destination_contact_phone'],
            'destination_address' => $request['destination_address'],
            'destination_area_id' => $request['destination_area_id'],
            'courier_company' => $request['courier_code'],
            'courier_type' => $request['courier_service_code'],
            'delivery_type' => 'now',
            'items' => [[
                'name' => 'Barang',
                'value' => $request['item_value'],
                'quantity' => 1,
                'weight' => $request['weight_gram'],
            ]],
        ]);

        $body = $response->json();

        if (! $response->successful() || empty($body['success'])) {
            throw new RuntimeException('Biteship shipment creation failed: '.($body['error'] ?? $response->body()));
        }

        return $this->mapOrderResponse($body);
    }

    public function getShipmentStatus(string $providerOrderId): array
    {
        $response = $this->client()->get("/v1/orders/{$providerOrderId}");

        $body = $response->json();

        if (! $response->successful() || empty($body['success'])) {
            throw new RuntimeException('Biteship shipment status lookup failed: '.($body['error'] ?? $response->body()));
        }

        return $this->mapOrderResponse($body);
    }

    public function cancelShipment(string $providerOrderId, string $reasonCode, ?string $reason = null): array
    {
        $payload = ['cancellation_reason_code' => $reasonCode];

        if ($reason !== null) {
            $payload['cancellation_reason'] = $reason;
        }

        $response = $this->client()->post("/v1/orders/{$providerOrderId}/cancel", $payload);

        $body = $response->json();

        if (! $response->successful() || empty($body['success'])) {
            throw new RuntimeException('Biteship shipment cancellation failed: '.($body['error'] ?? $response->body()));
        }

        return [
            'provider_order_id' => (string) $body['id'],
            'status' => $body['status'],
        ];
    }

    /**
     * @return array{provider_order_id: string, status: string, tracking_number: ?string, courier_code: string, courier_name: string, courier_service: string, price: ?int}
     */
    private function mapOrderResponse(array $body): array
    {
        $courier = $body['courier'] ?? [];

        return [
            'provider_order_id' => (string) $body['id'],
            'status' => $body['status'],
            'tracking_number' => $courier['waybill_id'] ?? null,
            'courier_code' => $courier['company'] ?? '',
            'courier_name' => mb_strtoupper($courier['company'] ?? ''),
            'courier_service' => $courier['type'] ?? '',
            'price' => isset($body['price']) ? (int) $body['price'] : null,
        ];
    }

    private function client()
    {
        return Http::baseUrl(config('services.biteship.base_url'))
            ->withHeaders(['authorization' => config('services.biteship.api_key')])
            ->acceptJson();
    }
}
