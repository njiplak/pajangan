<?php

namespace App\Http\Controllers\Storefront;

use App\Http\Controllers\Controller;
use App\Models\BundleItem;
use App\Models\Product;
use Illuminate\Http\Request;
use Inertia\Inertia;

class ProductController extends Controller
{
    public function index(Request $request)
    {
        $search = trim((string) $request->query('q', ''));

        $products = Product::query()
            ->sellable()
            ->where('is_active', true)
            ->when($search !== '', fn ($query) => $query->where('name', 'like', "%{$search}%"))
            ->latest()
            ->paginate(12)
            ->withQueryString();

        return Inertia::render('storefront/products/index', [
            'products' => $products->through(fn (Product $product) => $this->summarize($product)),
            'search' => $search,
        ]);
    }

    public function show(string $slug)
    {
        $product = Product::query()
            ->sellable()
            ->where('slug', $slug)
            ->where('is_active', true)
            ->firstOrFail();

        $customer = request()->user('customer');

        return Inertia::render('storefront/products/show', [
            'product' => $this->detail($product),
            'inWishlist' => $customer
                ? $customer->wishlist()->where('product_id', $product->id)->exists()
                : false,
        ]);
    }

    private function summarize(Product $product): array
    {
        return [
            'id' => $product->id,
            'name' => $product->name,
            'slug' => $product->slug,
            'price' => $product->price,
            'discount_percent' => $product->discount_percent,
            'effective_price' => $product->effectivePrice(),
            'stock' => $product->availableStock(),
            'is_bundle' => $product->is_bundle,
            'producer_name' => $product->producer_name,
            'producer_region' => $product->producer_region,
            'image' => $product->getFirstMediaUrl('images') ?: null,
        ];
    }

    private function detail(Product $product): array
    {
        return array_merge($this->summarize($product), [
            'description' => $product->description,
            'images' => $product->getMedia('images')->map(fn ($media) => $media->getUrl())->values(),
            'bundle_items' => $product->is_bundle
                ? $product->bundleItems
                    ->filter(fn (BundleItem $item) => $item->product !== null)
                    ->map(fn (BundleItem $item) => [
                        'product_id' => $item->product->id,
                        'name' => $item->product->name,
                        'slug' => $item->product->slug,
                        'quantity' => $item->quantity,
                        'effective_price' => $item->product->effectivePrice(),
                        'image' => $item->product->getFirstMediaUrl('images') ?: null,
                    ])->values()
                : [],
            // Lets the page show what the contents would cost separately.
            'components_total' => $product->componentsTotal(),
        ]);
    }
}
