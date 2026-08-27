<?php

namespace App\Service\Shipping;

use App\Contract\Shipping\ShippingProviderContract;
use InvalidArgumentException;

/**
 * Registry of all known ShippingProviderContract implementations. Callers
 * resolve a provider by key rather than depending on a concrete
 * implementation, which is what makes the integration swappable at
 * runtime (e.g. Biteship today, another aggregator later).
 */
class ShippingProviderManager
{
    /** @var array<string, ShippingProviderContract> */
    private array $providers = [];

    public function register(ShippingProviderContract $provider): void
    {
        $this->providers[$provider->key()] = $provider;
    }

    public function resolve(string $key): ShippingProviderContract
    {
        if (! isset($this->providers[$key])) {
            throw new InvalidArgumentException("Unknown shipping provider [{$key}].");
        }

        return $this->providers[$key];
    }

    public function has(string $key): bool
    {
        return isset($this->providers[$key]);
    }

    /**
     * @return string[] keys of every registered provider, regardless of
     *                  whether it's currently configured
     */
    public function all(): array
    {
        return array_keys($this->providers);
    }

    /**
     * @return string[] keys of providers that have credentials set
     */
    public function configured(): array
    {
        return array_keys(array_filter(
            $this->providers,
            fn (ShippingProviderContract $provider) => $provider->isConfigured()
        ));
    }
}
