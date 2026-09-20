<?php

namespace App\Service\Shipping;

use Illuminate\Support\Facades\Session;

/**
 * The destination a visitor has told us to ship to, kept for the session
 * so they are asked once rather than on every page.
 *
 * This holds an area, not an address: a rate quote needs only the
 * courier's area id, and the street address is still collected at
 * checkout where it is actually used. Nothing here is ever guessed — an
 * unset destination means the storefront shows no estimate at all.
 */
class ShippingDestination
{
    private const SESSION_KEY = 'shipping_destination';

    /**
     * @return array{id: string, name: string}|null
     */
    public function get(): ?array
    {
        $stored = Session::get(self::SESSION_KEY);

        if (! is_array($stored) || blank($stored['id'] ?? null) || blank($stored['name'] ?? null)) {
            return null;
        }

        return ['id' => (string) $stored['id'], 'name' => (string) $stored['name']];
    }

    public function set(string $areaId, string $areaName): void
    {
        Session::put(self::SESSION_KEY, ['id' => $areaId, 'name' => $areaName]);
    }

    public function forget(): void
    {
        Session::forget(self::SESSION_KEY);
    }
}
