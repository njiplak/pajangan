<?php

namespace App\Contract\Order;

use App\Contract\BaseContract;

interface OrderContract extends BaseContract
{
    public function updateStatus(int $id, string $status);

    public function updateShipping(int $id, array $data);
}
