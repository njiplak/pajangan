<?php

namespace App\View\Composers;

use App\Contract\Setting\SettingContract;
use App\Models\Page;
use App\Models\Product;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Str;
use Illuminate\View\View;

/**
 * Renders <title>/description/OG tags directly in the blade root view, so
 * crawlers and link-preview bots that don't execute JavaScript (WhatsApp,
 * Facebook, Twitter, etc.) see accurate per-page content on the very first
 * response. There is no persistent Node/Bun process available on the target
 * hosting to run true Inertia SSR, so this is a plain-PHP substitute scoped
 * to just the head tags rather than full page markup.
 */
class SeoMetaComposer
{
    public function __construct(private readonly SettingContract $settings) {}

    public function compose(View $view): void
    {
        $settings = $this->settings->allAsKeyValue();
        $appName = config('app.name', 'Laravel');
        $description = $settings['seo_default_description'] ?? '';
        $image = $settings['seo_og_image_url'] ?? '';
        $title = $appName;

        $routeName = Route::currentRouteName();

        if ($routeName === 'products.show') {
            $slug = Route::current()?->parameter('slug');
            $product = Product::query()
                ->where('slug', $slug)
                ->where('is_active', true)
                ->first();

            if ($product) {
                $title = "{$product->name} - {$appName}";
                $description = $product->description
                    ? Str::limit($product->description, 160)
                    : $description;
                $image = $product->getFirstMediaUrl('images') ?: $image;
            }
        } elseif ($routeName === 'home') {
            $description = $settings['storefront_hero_subtitle'] ?? $description;
        } elseif ($routeName === 'about') {
            $page = Page::query()->where('slug', 'tentang-kami')->where('is_active', true)->first();

            if ($page) {
                $title = "{$page->title} - {$appName}";
                $description = $page->meta_description ?: $description;
            }
        }

        $view->with([
            'metaTitle' => $title,
            'metaDescription' => $description,
            'metaImage' => $image,
        ]);
    }
}
