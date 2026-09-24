<?php

namespace App\Service\Order;

use App\Models\Order;
use App\Models\Product;
use Illuminate\Support\Facades\DB;

/**
 * Puts an order's stock back.
 *
 * Reverses the draw recorded on the order at checkout rather than
 * recomputing it from the products: a bundle's composition may have been
 * edited since, and the only correct thing to return is what was actually
 * taken. Releasing is idempotent — `stock_released_at` makes a second
 * attempt a no-op, so an expiry job and a staff cancellation racing on the
 * same order cannot hand back the stock twice.
 */
class OrderStockReleaser
{
    /**
     * @return bool true when this call is the one that released the stock
     */
    public function release(Order $order): bool
    {
        return DB::transaction(function () use ($order) {
            $locked = Order::query()->whereKey($order->id)->lockForUpdate()->first();

            if (! $locked || $locked->stock_released_at !== null) {
                return false;
            }

            foreach (($locked->stock_draw ?? []) as $productId => $units) {
                $units = (int) $units;

                if ($units < 1) {
                    continue;
                }

                $product = Product::query()->whereKey((int) $productId)->lockForUpdate()->first();

                // A product deleted since the order was placed has nowhere
                // to return to; the rest of the order still releases.
                $product?->increment('stock', $units);
            }

            $locked->stock_released_at = now();
            $locked->save();

            return true;
        });
    }
}
