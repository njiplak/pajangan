<?php

namespace App\Contract\Payment;

/**
 * Normalized transaction status vocabulary, independent of any single
 * gateway's own status strings. PaymentGatewayContract implementations
 * must translate their provider's status into one of these.
 */
final class PaymentStatus
{
    public const PENDING = 'pending';

    public const PAID = 'paid';

    public const FAILED = 'failed';

    public const EXPIRED = 'expired';

    public const CANCELLED = 'cancelled';

    public const REFUNDED = 'refunded';

    public const ALL = [
        self::PENDING,
        self::PAID,
        self::FAILED,
        self::EXPIRED,
        self::CANCELLED,
        self::REFUNDED,
    ];
}
