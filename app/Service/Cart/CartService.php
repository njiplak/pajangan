<?php

namespace App\Service\Cart;

use App\Models\Product;
use Illuminate\Support\Facades\Session;

class CartService
{
    private const SESSION_KEY = 'cart';

    /**
     * Raw session cart as [product_id => quantity].
     */
    public function raw(): array
    {
        return Session::get(self::SESSION_KEY, []);
    }

    public function count(): int
    {
        return (int) array_sum($this->raw());
    }

    public function add(int $productId, int $quantity): void
    {
        $product = Product::query()->where('is_active', true)->findOrFail($productId);

        $cart = $this->raw();
        $current = $cart[$productId] ?? 0;
        $desired = $current + max(1, $quantity);

        $cart[$productId] = max(0, min($desired, $product->stock));

        if ($cart[$productId] <= 0) {
            unset($cart[$productId]);
        }

        Session::put(self::SESSION_KEY, $cart);
    }

    public function updateQuantity(int $productId, int $quantity): void
    {
        $cart = $this->raw();

        if (! array_key_exists($productId, $cart)) {
            return;
        }

        if ($quantity <= 0) {
            unset($cart[$productId]);
            Session::put(self::SESSION_KEY, $cart);

            return;
        }

        $product = Product::find($productId);
        $maxStock = $product?->stock ?? 0;
        $cart[$productId] = min($quantity, max(0, $maxStock));

        if ($cart[$productId] <= 0) {
            unset($cart[$productId]);
        }

        Session::put(self::SESSION_KEY, $cart);
    }

    public function remove(int $productId): void
    {
        $cart = $this->raw();
        unset($cart[$productId]);
        Session::put(self::SESSION_KEY, $cart);
    }

    public function clear(): void
    {
        Session::forget(self::SESSION_KEY);
    }

    /**
     * Total shipment weight for the current cart, for quoting courier
     * rates. Uses live product weights, not whatever was true when items
     * were added.
     */
    public function totalWeightGrams(): int
    {
        $cart = $this->raw();

        if (empty($cart)) {
            return 0;
        }

        $products = Product::query()->whereIn('id', array_keys($cart))->get()->keyBy('id');

        $weight = 0;

        foreach ($cart as $productId => $quantity) {
            $product = $products->get($productId);

            if ($product) {
                $weight += $product->weight_gram * $quantity;
            }
        }

        return $weight;
    }

    /**
     * Resolve the cart against live product data, self-healing the session
     * cart if a product was deleted, deactivated, or its stock dropped below
     * what's currently held in the cart.
     */
    public function summary(): array
    {
        $cart = $this->raw();

        if (empty($cart)) {
            return ['items' => [], 'subtotal' => 0, 'total' => 0];
        }

        $products = Product::query()->whereIn('id', array_keys($cart))->get()->keyBy('id');

        $items = [];
        $subtotal = 0;
        $changed = false;

        foreach ($cart as $productId => $quantity) {
            $product = $products->get($productId);

            if (! $product || ! $product->is_active) {
                unset($cart[$productId]);
                $changed = true;

                continue;
            }

            $clampedQuantity = min($quantity, $product->stock);

            if ($clampedQuantity <= 0) {
                unset($cart[$productId]);
                $changed = true;

                continue;
            }

            if ($clampedQuantity !== $quantity) {
                $cart[$productId] = $clampedQuantity;
                $changed = true;
            }

            $lineSubtotal = $product->effectivePrice() * $clampedQuantity;
            $subtotal += $lineSubtotal;

            $items[] = [
                'product_id' => $product->id,
                'name' => $product->name,
                'slug' => $product->slug,
                'price' => $product->price,
                'discount_percent' => $product->discount_percent,
                'effective_price' => $product->effectivePrice(),
                'quantity' => $clampedQuantity,
                'stock' => $product->stock,
                'subtotal' => $lineSubtotal,
                'image' => $product->getFirstMediaUrl('images') ?: null,
            ];
        }

        if ($changed) {
            Session::put(self::SESSION_KEY, $cart);
        }

        return [
            'items' => $items,
            'subtotal' => $subtotal,
            'total' => $subtotal,
        ];
    }
}
