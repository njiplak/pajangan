<?php

namespace App\Service\Shipping;

use App\Contract\Setting\SettingContract;

/**
 * The free-shipping rule, in one place: checkout prices orders with it and
 * the payment service charges with it, so the two can never disagree.
 *
 * Off unless `free_shipping_min_subtotal` is set above zero. The store
 * absorbs ongkir up to `free_shipping_max_subsidy` (zero means no cap);
 * above the cap the customer pays the difference.
 */
class FreeShipping
{
    public function __construct(private readonly SettingContract $settings) {}

    public function threshold(): int
    {
        return max(0, (int) ($this->settings->allAsKeyValue()['free_shipping_min_subtotal'] ?? 0));
    }

    public function cap(): int
    {
        return max(0, (int) ($this->settings->allAsKeyValue()['free_shipping_max_subsidy'] ?? 0));
    }

    public function enabled(): bool
    {
        return $this->threshold() > 0;
    }

    public function discountFor(int $subtotal, int $shippingCost): int
    {
        if (! $this->enabled() || $subtotal < $this->threshold() || $shippingCost <= 0) {
            return 0;
        }

        $cap = $this->cap();

        return $cap > 0 ? min($shippingCost, $cap) : $shippingCost;
    }
}
