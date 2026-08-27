<?php

namespace App\Contract\Shipping;

/**
 * Implemented once per shipping/courier aggregator (Biteship, RajaOngkir,
 * ...). Callers resolve a concrete implementation through
 * ShippingProviderManager and only ever depend on this contract.
 *
 * Shaped around our domain, not the vendor's payload — quoteRates()
 * accepts and returns our own array shapes; each driver translates.
 */
interface ShippingProviderContract
{
    /**
     * Unique key identifying this provider, e.g. 'biteship'.
     */
    public function key(): string;

    /**
     * Human-readable name for admin UI display, e.g. 'Biteship'.
     */
    public function label(): string;

    /**
     * Whether this provider has the credentials/config it needs to
     * operate (a local config check, no network call).
     */
    public function isConfigured(): bool;

    /**
     * Search destination areas by free-text name (city/district/postal
     * code) so staff can resolve a human-typed address to the area id a
     * rate quote needs.
     *
     * @return array<int, array{id: string, name: string, postal_code: ?string}>
     */
    public function searchAreas(string $query): array;

    /**
     * Quote shipping rates from the store's configured origin to a
     * destination area for a shipment of the given weight and value.
     *
     * @param  array{destination_area_id: string, weight_gram: int, item_value: int}  $request
     * @return array<int, array{
     *     courier_code: string,
     *     courier_name: string,
     *     courier_service_code: string,
     *     courier_service_name: string,
     *     price: int,
     *     duration: ?string,
     *     collection_methods: array<int, string>,
     * }>
     */
    public function quoteRates(array $request): array;

    /**
     * Create a real shipment for the given courier/service — this has a
     * real-world effect (a courier pickup is requested, or a drop-off
     * waybill is issued) and, once the store's balance is charged, is not
     * a free no-op like quoteRates(). Sender identity comes from the
     * provider's own config, not the caller.
     *
     * @param  array{
     *     destination_area_id: string,
     *     destination_contact_name: string,
     *     destination_contact_phone: string,
     *     destination_address: string,
     *     weight_gram: int,
     *     item_value: int,
     *     courier_code: string,
     *     courier_service_code: string,
     * }  $request
     * @return array{
     *     provider_order_id: string,
     *     status: string,
     *     tracking_number: ?string,
     *     courier_code: string,
     *     courier_name: string,
     *     courier_service: string,
     *     price: ?int,
     * }
     */
    public function createShipment(array $request): array;

    /**
     * Fetch the current, authoritative state of a shipment directly from
     * the provider. Used to refresh an order's shipping status without
     * trusting an inbound webhook's payload content — the webhook only
     * needs to say "something changed for order X", this confirms what.
     *
     * @return array{
     *     provider_order_id: string,
     *     status: string,
     *     tracking_number: ?string,
     *     courier_code: string,
     *     courier_name: string,
     *     courier_service: string,
     *     price: ?int,
     * }
     */
    public function getShipmentStatus(string $providerOrderId): array;

    /**
     * Cancel a previously created shipment. Whether this actually stops a
     * courier that's already been dispatched is up to the provider/courier,
     * not something this call controls — it only requests the cancellation.
     *
     * @return array{provider_order_id: string, status: string}
     */
    public function cancelShipment(string $providerOrderId, string $reasonCode, ?string $reason = null): array;
}
