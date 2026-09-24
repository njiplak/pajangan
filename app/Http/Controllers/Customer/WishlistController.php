<?php

namespace App\Http\Controllers\Customer;

use App\Http\Controllers\Controller;
use App\Models\Customer;
use App\Models\Product;
use App\Models\WishlistItem;
use Illuminate\Support\Facades\Auth;
use Inertia\Inertia;

class WishlistController extends Controller
{
    public function index()
    {
        $items = $this->customer()->wishlist()
            ->with(['product' => fn ($query) => $query->sellable()])
            ->latest()
            ->get()
            // A wished-for product that was deleted or hidden simply drops
            // out rather than showing as a broken card.
            ->filter(fn (WishlistItem $item) => $item->product && $item->product->is_active)
            ->map(fn (WishlistItem $item) => [
                'id' => $item->product->id,
                'name' => $item->product->name,
                'slug' => $item->product->slug,
                'price' => $item->product->price,
                'discount_percent' => $item->product->discount_percent,
                'effective_price' => $item->product->effectivePrice(),
                'stock' => $item->product->availableStock(),
                'is_bundle' => $item->product->is_bundle,
                'producer_name' => $item->product->producer_name,
                'producer_region' => $item->product->producer_region,
                'image' => $item->product->getFirstMediaUrl('images') ?: null,
            ])
            ->values();

        return Inertia::render('storefront/account/wishlist', [
            'products' => $items,
        ]);
    }

    public function toggle(Product $product)
    {
        $customer = $this->customer();

        $existing = $customer->wishlist()->where('product_id', $product->id)->first();

        if ($existing) {
            $existing->delete();
        } elseif ($product->is_active) {
            $customer->wishlist()->firstOrCreate(['product_id' => $product->id]);
        }

        return back();
    }

    private function customer(): Customer
    {
        /** @var Customer */
        return Auth::guard('customer')->user();
    }
}
