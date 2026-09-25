<?php

namespace App\Contract\Order;

use App\Contract\BaseContract;

interface OrderContract extends BaseContract
{
    public function updateStatus(int $id, string $status);

    /**
     * @param  array{0: string, 1: string}|null  $activity  [action, description] to log with the change
     */
    public function updateShipping(int $id, array $data, ?array $activity = null);

    public function recordRefund(int $id, ?string $reference);

    public function updateDetails(int $id, array $data);

    public function addNote(int $id, string $body);
}
