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

    private const PARTS = ['postal_code', 'district', 'city', 'province'];

    /**
     * The structured parts are whatever the courier supplied when the area
     * was picked, so checkout can fill (and lock) them without asking.
     *
     * @return array{id: string, name: string, postal_code: ?string, district: ?string, city: ?string, province: ?string}|null
     */
    public function get(): ?array
    {
        $stored = Session::get(self::SESSION_KEY);

        if (! is_array($stored) || blank($stored['id'] ?? null) || blank($stored['name'] ?? null)) {
            return null;
        }

        $destination = ['id' => (string) $stored['id'], 'name' => (string) $stored['name']];

        foreach (self::PARTS as $part) {
            $destination[$part] = filled($stored[$part] ?? null) ? (string) $stored[$part] : null;
        }

        return $destination;
    }

    /**
     * @param  array<string, ?string>  $parts  postal_code, district, city, province
     */
    public function set(string $areaId, string $areaName, array $parts = []): void
    {
        Session::put(
            self::SESSION_KEY,
            ['id' => $areaId, 'name' => $areaName] + array_intersect_key($parts, array_flip(self::PARTS))
        );
    }

    public function forget(): void
    {
        Session::forget(self::SESSION_KEY);
    }
}
