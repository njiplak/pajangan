<?php

namespace App\Http\Controllers\Storefront;

use App\Http\Controllers\Controller;
use App\Models\Product;
use Illuminate\Http\Request;
use Inertia\Inertia;

class ProductController extends Controller
{
    public function index(Request $request)
    {
        $search = trim((string) $request->query('q', ''));

        $products = Product::query()
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
            ->where('slug', $slug)
            ->where('is_active', true)
            ->firstOrFail();

        return Inertia::render('storefront/products/show', [
            'product' => $this->detail($product),
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
            'stock' => $product->stock,
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
        ]);
    }
}
