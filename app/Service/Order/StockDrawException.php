<?php

namespace App\Service\Order;

use RuntimeException;

/**
 * Why a set of products can't be sold right now. Callers word it for
 * their own audience: a shopper's cart and a staff order read differently.
 */
class StockDrawException extends RuntimeException
{
    public const BUNDLE_CHANGED = 'bundle_changed';

    public const UNAVAILABLE = 'unavailable';

    /** One line asks for more than its product can supply. */
    public const LINE_SHORT = 'line_short';

    /** Lines pass alone but together draw more of a component than exists. */
    public const TOTAL_SHORT = 'total_short';

    public function __construct(
        public readonly string $reason,
        public readonly ?string $productName = null,
        public readonly ?int $available = null,
    ) {
        parent::__construct($reason);
    }
}
