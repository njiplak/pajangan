<?php

namespace App\Service\Shipping;

use Illuminate\Support\Facades\Cache;
use RuntimeException;

/**
 * Cheapest courier rate for a shipment, for the "ongkir mulai Rp X" line
 * the storefront shows before checkout.
 *
 * This is deliberately an estimate and never a price we charge: quotes
 * are cached, so a stale one could differ from what the courier wants
 * today. Checkout re-quotes live against the real cart and pays from
 * that, so a cached number here can never reach an order total.
 */
class ShippingEstimator
{
    private const CACHE_TTL_SECONDS = 21600; // 6 hours

    public function __construct(private readonly ShippingProviderManager $shipping) {}

    /**
     * @return array{courier_name: string, courier_service_name: string, price: int, duration: ?string}|null
     *                                                                                                       null when the provider is unconfigured or quotes nothing for this route
     */
    public function cheapest(string $destinationAreaId, int $weightGram, int $itemValue): ?array
    {
        if ($weightGram < 1) {
            return null;
        }

        $key = sprintf(
            'shipping:cheapest:%s:%s:%d:%d',
            config('services.biteship.origin_area_id'),
            $destinationAreaId,
            $weightGram,
            $itemValue,
        );

        return Cache::remember($key, self::CACHE_TTL_SECONDS, function () use ($destinationAreaId, $weightGram, $itemValue) {
            try {
                $rates = $this->shipping->resolve('biteship')->quoteRates([
                    'destination_area_id' => $destinationAreaId,
                    'weight_gram' => $weightGram,
                    'item_value' => $itemValue,
                ]);
            } catch (RuntimeException) {
                // An estimate is decoration: a provider outage must not take
                // the product page down with it. Cache::remember stores the
                // null but never serves one, so the next request retries
                // rather than holding an outage for the whole TTL.
                return null;
            }

            $cheapest = collect($rates)->sortBy('price')->first();

            if (! $cheapest) {
                return null;
            }

            return [
                'courier_name' => $cheapest['courier_name'],
                'courier_service_name' => $cheapest['courier_service_name'],
                'price' => (int) $cheapest['price'],
                'duration' => $cheapest['duration'] ?? null,
            ];
        });
    }
}
