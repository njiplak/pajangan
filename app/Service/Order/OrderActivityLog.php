<?php

namespace App\Service\Order;

use App\Models\Order;
use App\Models\OrderActivity;

/**
 * The order's history: what happened and who did it. Written inside the
 * same transaction as the change where there is one, so a change and its
 * log line commit or roll back together.
 */
class OrderActivityLog
{
    public function record(Order $order, string $action, string $description, ?int $userId = null): OrderActivity
    {
        return OrderActivity::create([
            'order_id' => $order->id,
            'user_id' => $userId,
            'action' => $action,
            'description' => $description,
        ]);
    }
}
