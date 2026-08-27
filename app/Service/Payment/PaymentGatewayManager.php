<?php

namespace App\Service\Payment;

use App\Contract\Payment\PaymentGatewayContract;
use InvalidArgumentException;

/**
 * Registry of all known PaymentGatewayContract implementations. Callers
 * resolve a gateway by key rather than depending on a concrete
 * implementation, which is what makes the integration provider-agnostic
 * and swappable at runtime (e.g. via a Setting).
 */
class PaymentGatewayManager
{
    /** @var array<string, PaymentGatewayContract> */
    private array $gateways = [];

    public function register(PaymentGatewayContract $gateway): void
    {
        $this->gateways[$gateway->key()] = $gateway;
    }

    public function resolve(string $key): PaymentGatewayContract
    {
        if (! isset($this->gateways[$key])) {
            throw new InvalidArgumentException("Unknown payment gateway [{$key}].");
        }

        return $this->gateways[$key];
    }

    public function has(string $key): bool
    {
        return isset($this->gateways[$key]);
    }

    /**
     * @return string[] keys of every registered gateway, regardless of
     *                  whether it's currently configured
     */
    public function all(): array
    {
        return array_keys($this->gateways);
    }

    /**
     * @return string[] keys of gateways that have credentials set
     */
    public function configured(): array
    {
        return array_keys(array_filter(
            $this->gateways,
            fn (PaymentGatewayContract $gateway) => $gateway->isConfigured()
        ));
    }
}
