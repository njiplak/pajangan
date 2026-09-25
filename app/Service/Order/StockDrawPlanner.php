<?php

namespace App\Service\Order;

use App\Models\BundleItem;
use App\Models\Order;
use App\Models\OrderItem;
use App\Models\Product;
use App\Service\Stock\StockLedger;
use Illuminate\Support\Collection;

/**
 * Prices a set of products and works out exactly which stock they take,
 * under row locks, then takes it. Shared by storefront checkout and
 * staff-entered orders so both oversell-guard bundles the same way.
 */
class StockDrawPlanner
{
    public function __construct(private readonly StockLedger $ledger) {}

    /**
     * Must run inside a transaction: the product rows stay locked until it ends.
     *
     * @param  array<int, int>  $quantities  product id => quantity
     * @return array{lines: list<array{product: Product, quantity: int, unit_price: int, subtotal: int}>, subtotal: int, weight_gram: int, draw: array<int, int>, products: Collection<int, Product>}
     *
     * @throws StockDrawException
     */
    public function plan(array $quantities): array
    {
        $ids = array_keys($quantities);

        // A bundle sells its components' stock, so the rows to lock are
        // the requested products plus everything those bundles contain.
        $componentIds = BundleItem::query()
            ->whereIn('bundle_id', $ids)
            ->pluck('product_id')
            ->all();

        // Lock the involved product rows so a concurrent order can't
        // oversell the same stock between our read and our write.
        $products = Product::query()
            ->whereIn('id', array_unique(array_merge($ids, $componentIds)))
            ->lockForUpdate()
            ->get()
            ->keyBy('id');

        // Re-read composition now that the rows are held: a bundle
        // edited between the two reads must not slip past this check.
        $bundleItems = BundleItem::query()->whereIn('bundle_id', $ids)->get();

        foreach ($bundleItems as $bundleItem) {
            if (! $products->has($bundleItem->product_id)) {
                throw new StockDrawException(StockDrawException::BUNDLE_CHANGED);
            }
        }

        // Point each bundle at the locked component rows, so
        // availableStock() reads what we hold rather than issuing a
        // fresh unlocked query through the relation.
        $itemsByBundle = $bundleItems->groupBy('bundle_id');

        foreach ($products as $product) {
            if ($product->is_bundle) {
                $items = $itemsByBundle->get($product->id, collect())->each(
                    fn (BundleItem $bundleItem) => $bundleItem->setRelation('product', $products->get($bundleItem->product_id))
                );

                $product->setRelation('bundleItems', $items);
            }
        }

        $lines = [];
        $subtotal = 0;
        $weightGrams = 0;
        $draw = [];

        foreach ($quantities as $productId => $quantity) {
            $product = $products->get($productId);

            if (! $product || ! $product->is_active) {
                throw new StockDrawException(StockDrawException::UNAVAILABLE, $product?->name);
            }

            $available = $product->availableStock();

            if ($available < $quantity) {
                throw new StockDrawException(StockDrawException::LINE_SHORT, $product->name, $available);
            }

            $unitPrice = $product->effectivePrice();
            $lineSubtotal = $unitPrice * $quantity;
            $subtotal += $lineSubtotal;
            $weightGrams += $product->shippingWeightGram() * $quantity;

            if ($product->is_bundle) {
                foreach ($product->bundleItems as $bundleItem) {
                    $draw[$bundleItem->product_id] =
                        ($draw[$bundleItem->product_id] ?? 0) + ($bundleItem->quantity * $quantity);
                }
            } else {
                $draw[$productId] = ($draw[$productId] ?? 0) + $quantity;
            }

            $lines[] = [
                'product' => $product,
                'quantity' => $quantity,
                'unit_price' => $unitPrice,
                'subtotal' => $lineSubtotal,
            ];
        }

        // Per-line checks aren't enough: a bundle and the component's
        // own listing can each pass alone yet exceed the stock together.
        foreach ($draw as $componentId => $units) {
            $component = $products->get($componentId);

            if (! $component || $component->stock < $units) {
                throw new StockDrawException(StockDrawException::TOTAL_SHORT, $component?->name);
            }
        }

        return [
            'lines' => $lines,
            'subtotal' => $subtotal,
            'weight_gram' => $weightGrams,
            'draw' => $draw,
            'products' => $products,
        ];
    }

    /**
     * Writes the order's lines and takes the stock. Same transaction as plan().
     */
    public function apply(array $plan, Order $order, string $reason, ?int $userId = null): void
    {
        foreach ($plan['lines'] as $line) {
            OrderItem::create([
                'order_id' => $order->id,
                'product_id' => $line['product']->id,
                'product_name' => $line['product']->name,
                'unit_price' => $line['unit_price'],
                'quantity' => $line['quantity'],
                'subtotal' => $line['subtotal'],
            ]);
        }

        foreach ($plan['draw'] as $componentId => $units) {
            $this->ledger->move($plan['products']->get($componentId), -$units, $reason, $order, $userId);
        }
    }
}
