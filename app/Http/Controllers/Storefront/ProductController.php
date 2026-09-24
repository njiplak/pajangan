<?php

namespace App\Http\Controllers\Storefront;

use App\Http\Controllers\Controller;
use App\Models\BundleItem;
use App\Models\Product;
use App\Models\ProductReview;
use Illuminate\Http\Request;
use Inertia\Inertia;

class ProductController extends Controller
{
    public function index(Request $request)
    {
        $search = trim((string) $request->query('q', ''));

        $products = Product::query()
            ->sellable()
            ->withRating()
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
            ->withRating()
            ->where('slug', $slug)
            ->where('is_active', true)
            ->firstOrFail();

        $customer = request()->user('customer');
        $myReview = $customer?->reviews()->where('product_id', $product->id)->first();

        return Inertia::render('storefront/products/show', [
            'product' => $this->detail($product),
            'inWishlist' => $customer
                ? $customer->wishlist()->where('product_id', $product->id)->exists()
                : false,
            'reviews' => $product->reviews()
                ->where('is_visible', true)
                ->with('customer:id,name')
                ->latest()
                ->take(10)
                ->get()
                ->map(fn (ProductReview $review) => $this->presentReview($review))
                ->values(),
            'myReview' => $myReview ? [
                'rating' => $myReview->rating,
                'body' => $myReview->body,
                // Shown to its author so a hidden review is not a mystery.
                'is_visible' => $myReview->is_visible,
            ] : null,
            'canReview' => (bool) $customer?->completedOrderContaining($product),
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
            'rating_avg' => $product->rating_avg !== null ? round((float) $product->rating_avg, 1) : null,
            'rating_count' => (int) ($product->rating_count ?? 0),
            'producer_name' => $product->producer_name,
            'producer_region' => $product->producer_region,
            'image' => $product->getFirstMediaUrl('images') ?: null,
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function presentReview(ProductReview $review): array
    {
        return [
            'id' => $review->id,
            'rating' => $review->rating,
            'body' => $review->body,
            'author' => $review->authorName(),
            'created_at' => $review->created_at?->toIso8601String(),
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
