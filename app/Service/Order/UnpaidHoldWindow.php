<?php

namespace App\Service\Order;

use App\Contract\Setting\SettingContract;

/**
 * How long an unpaid order keeps its stock before it is cancelled.
 * Chat orders get longer: their customers usually pay by manual
 * transfer, which staff confirm by hand, often the next day.
 */
class UnpaidHoldWindow
{
    public const DEFAULT_ONLINE_HOURS = 24;

    public const DEFAULT_MANUAL_HOURS = 72;

    public function __construct(private readonly SettingContract $settings) {}

    public function onlineHours(): int
    {
        return (int) ($this->settings->allAsKeyValue()['order_unpaid_hold_hours'] ?? self::DEFAULT_ONLINE_HOURS);
    }

    /**
     * 0 means manual orders are never cancelled automatically.
     */
    public function manualHours(): int
    {
        return max(0, (int) ($this->settings->allAsKeyValue()['manual_order_unpaid_hold_hours'] ?? self::DEFAULT_MANUAL_HOURS));
    }
}
