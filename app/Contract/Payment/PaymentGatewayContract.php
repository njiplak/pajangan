<?php

namespace App\Contract\Payment;

use App\Models\Order;

/**
 * Implemented once per payment gateway (Midtrans, Xendit, ...). Callers
 * resolve a concrete implementation at runtime (e.g. by the active
 * gateway key in Settings) and only ever depend on this contract.
 */
interface PaymentGatewayContract
{
    /**
     * Unique key identifying this gateway, e.g. 'midtrans', 'xendit'.
     * Used to select the active implementation and to tag orders with
     * which gateway they were paid through.
     */
    public function key(): string;

    /**
     * Human-readable name for admin UI display, e.g. 'Tripay'.
     */
    public function label(): string;

    /**
     * Whether this gateway has the credentials it needs to operate
     * (a local config check, no network call). Used to decide which
     * gateways can be offered/enabled at runtime.
     */
    public function isConfigured(): bool;

    /**
     * List the payment channels this gateway lets a merchant fix ahead
     * of creating a transaction (e.g. Tripay/Duitku's bank/e-wallet
     * codes). Gateways that hand channel selection off to their own
     * hosted payment page instead (Midtrans Snap, Xendit Invoice, DOKU
     * Checkout) have nothing to list here and return an empty array.
     *
     * fee_flat/fee_percent are the gateway's own real, channel-specific
     * transaction fee where it exposes one (e.g. Tripay's channel list
     * includes it directly) — a suggested default for the admin-fee
     * config UI, not something callers should rely on for billing math
     * themselves. Null when the gateway doesn't expose per-channel fees.
     *
     * @return array<int, array{code: string, name: string, fee_flat: ?int, fee_percent: ?float}>
     */
    public function listChannels(): array;

    /**
     * Start a payment transaction for the given order.
     *
     * $amount is passed explicitly rather than read from $order->total,
     * so whether an admin fee is included is decided by the caller, not
     * by the gateway implementation.
     *
     * @param  array  $options  gateway-specific extras (e.g. preferred channel)
     * @return array{reference: string, redirect_url: ?string, token: ?string, expires_at: ?string, raw: array}
     */
    public function createTransaction(Order $order, int $amount, array $options = []): array;

    /**
     * Verify that an inbound webhook/callback payload actually originated
     * from this gateway (signature, HMAC, or equivalent check).
     *
     * $rawBody is the exact, unparsed request body. Some gateways (e.g.
     * Tripay) sign the raw bytes of the payload, which a re-encoded
     * array is not guaranteed to match byte-for-byte (key order,
     * escaping). Implementations that only need specific fields can
     * json_decode() internally.
     *
     * $headers is a flat, lowercase-keyed, single-value-per-key map of
     * the inbound HTTP request headers (e.g. ['x-callback-signature' =>
     * '...']), matching how Symfony's HeaderBag normalizes header names.
     */
    public function verifyNotification(string $rawBody, array $headers = []): bool;

    /**
     * Normalize an already-verified webhook payload into a common shape.
     * `status` must be one of PaymentStatus::ALL.
     *
     * @return array{reference: string, status: string, amount: int, paid_at: ?string, raw: array}
     */
    public function parseNotification(array $payload): array;

    /**
     * Query the current status of a transaction directly from the
     * gateway. Used as a reconciliation fallback when a webhook is
     * missed or arrives out of order.
     *
     * @return array{reference: string, status: string, amount: int, paid_at: ?string, raw: array}
     */
    public function getStatus(string $reference): array;
}
