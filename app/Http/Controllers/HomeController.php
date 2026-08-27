<?php

namespace App\Http\Controllers;

use App\Models\Banner;
use App\Models\Product;
use Inertia\Inertia;

class HomeController extends Controller
{
    public function index()
    {
        $banners = Banner::query()
            ->where('is_active', true)
            ->orderBy('sort_order')
            ->get()
            ->map(fn (Banner $banner) => [
                'id' => $banner->id,
                'title' => $banner->title,
                'link' => $banner->link,
                'image' => $banner->getFirstMediaUrl('image') ?: null,
            ]);

        $featuredProducts = Product::query()
            ->where('is_active', true)
            ->latest()
            ->take(8)
            ->get()
            ->map(fn (Product $product) => [
                'id' => $product->id,
                'name' => $product->name,
                'slug' => $product->slug,
                'price' => $product->price,
                'discount_percent' => $product->discount_percent,
                'effective_price' => $product->effectivePrice(),
                'producer_name' => $product->producer_name,
                'producer_region' => $product->producer_region,
                'image' => $product->getFirstMediaUrl('images') ?: null,
            ]);

        return Inertia::render('home', [
            'featuredProducts' => $featuredProducts,
            'banners' => $banners,
        ]);
    }
}
