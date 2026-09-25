<?php

namespace App\Service\Stock;

use App\Contract\Notification\StaffOrderNotifierContract;
use App\Contract\Setting\SettingContract;
use App\Models\Order;
use App\Models\Product;
use App\Models\StockMovement;
use Illuminate\Support\Facades\DB;
use RuntimeException;

/**
 * The one way stock changes, so every change has a line saying why and
 * who, and a product running low is noticed whichever path emptied it.
 */
class StockLedger
{
    public const DEFAULT_LOW_STOCK_THRESHOLD = 10;

    public function __construct(
        private readonly SettingContract $settings,
        private readonly StaffOrderNotifierContract $staffNotifier,
    ) {}

    /**
     * The caller must already hold a row lock on $product.
     *
     * @throws RuntimeException when the change would take stock below zero
     */
    public function move(
        Product $product,
        int $change,
        string $reason,
        ?Order $order = null,
        ?int $userId = null,
        ?string $note = null,
    ): ?StockMovement {
        if ($change === 0) {
            return null;
        }

        $before = (int) $product->stock;
        $after = $before + $change;

        if ($after < 0) {
            throw new RuntimeException("Stok {$product->name} sekarang {$before}; tidak bisa dikurangi ".abs($change).'.');
        }

        $product->stock = $after;
        $product->save();

        $movement = StockMovement::create([
            'product_id' => $product->id,
            'order_id' => $order?->id,
            'user_id' => $userId,
            'change' => $change,
            'stock_after' => $after,
            'reason' => $reason,
            'note' => $note,
        ]);

        $threshold = $this->lowStockThreshold();

        if (! $product->is_bundle && $before > $threshold && $after <= $threshold) {
            // Only once the draw is committed; a rolled-back checkout emptied nothing.
            DB::afterCommit(fn () => $this->staffNotifier->lowStock($product, $threshold));
        }

        return $movement;
    }

    /**
     * Locks the product and applies a staff correction to its current stock.
     *
     * @throws RuntimeException when the change would take stock below zero
     */
    public function adjust(int $productId, int $change, ?int $userId, ?string $note = null): Product
    {
        return DB::transaction(function () use ($productId, $change, $userId, $note) {
            $product = Product::query()->whereKey($productId)->lockForUpdate()->firstOrFail();

            $this->move($product, $change, StockMovement::REASON_ADJUSTMENT, null, $userId, $note);

            return $product;
        });
    }

    /**
     * The count a new product starts with; the stock itself is already set.
     */
    public function recordOpening(Product $product, ?int $userId): void
    {
        if ($product->is_bundle || (int) $product->stock === 0) {
            return;
        }

        StockMovement::create([
            'product_id' => $product->id,
            'user_id' => $userId,
            'change' => (int) $product->stock,
            'stock_after' => (int) $product->stock,
            'reason' => StockMovement::REASON_INITIAL,
        ]);
    }

    public function lowStockThreshold(): int
    {
        return (int) ($this->settings->allAsKeyValue()['low_stock_threshold'] ?? self::DEFAULT_LOW_STOCK_THRESHOLD);
    }
}
