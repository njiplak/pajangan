<?php

namespace App\Http\Controllers\Storefront;

use App\Http\Controllers\Controller;
use App\Models\Producer;
use App\Models\Product;
use Inertia\Inertia;

/**
 * The people behind the products. The storefront's whole pitch is
 * connecting Papuan producers with buyers; these pages are where a buyer
 * meets them.
 */
class ProducerController extends Controller
{
    public function index()
    {
        $producers = Producer::query()
            ->where('is_active', true)
            ->withCount(['products as product_count' => fn ($query) => $query->where('is_active', true)])
            ->orderBy('name')
            ->get()
            ->map(fn (Producer $producer) => [
                'name' => $producer->name,
                'slug' => $producer->slug,
                'region' => $producer->region,
                'photo' => $producer->getFirstMediaUrl('photo') ?: null,
                'product_count' => $producer->product_count,
            ]);

        return Inertia::render('storefront/producers/index', [
            'producers' => $producers,
        ]);
    }

    public function show(string $slug)
    {
        $producer = Producer::query()
            ->where('slug', $slug)
            ->where('is_active', true)
            ->firstOrFail();

        $products = $producer->products()
            ->sellable()
            ->withRating()
            ->where('is_active', true)
            ->latest()
            ->get()
            ->map(fn (Product $product) => ProductController::summarize($product))
            ->values();

        return Inertia::render('storefront/producers/show', [
            'producer' => [
                'name' => $producer->name,
                'region' => $producer->region,
                'story' => $producer->story,
                'photo' => $producer->getFirstMediaUrl('photo') ?: null,
            ],
            'products' => $products,
        ]);
    }
}
