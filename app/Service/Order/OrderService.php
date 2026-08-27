<?php

namespace App\Service\Order;

use App\Contract\Order\OrderContract;
use App\Models\Order;
use App\Service\BaseService;
use Exception;
use Illuminate\Support\Facades\DB;

class OrderService extends BaseService implements OrderContract
{
    protected array $relation = ['items'];

    public function __construct(Order $model)
    {
        parent::__construct($model);
    }

    public function updateStatus(int $id, string $status)
    {
        try {
            DB::beginTransaction();
            $order = $this->model->findOrFail($id);
            $order->update(['status' => $status]);
            DB::commit();

            return $order->fresh('items');
        } catch (Exception $e) {
            DB::rollBack();

            return $e;
        }
    }

    /**
     * Records the courier/rate staff chose for this order after looking it
     * up via a ShippingProviderContract. This is a record-keeping write
     * only — it does not touch `total` or reopen payment, since the order
     * may already be paid for the amount charged at checkout.
     */
    public function updateShipping(int $id, array $data)
    {
        try {
            DB::beginTransaction();
            $order = $this->model->findOrFail($id);
            $order->update($data);
            DB::commit();

            return $order->fresh('items');
        } catch (Exception $e) {
            DB::rollBack();

            return $e;
        }
    }
}
