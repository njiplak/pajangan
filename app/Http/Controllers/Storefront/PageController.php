<?php

namespace App\Http\Controllers\Storefront;

use App\Http\Controllers\Controller;
use App\Models\Page;
use Inertia\Inertia;

class PageController extends Controller
{
    public function about()
    {
        $page = Page::query()
            ->where('slug', 'tentang-kami')
            ->where('is_active', true)
            ->firstOrFail();

        return Inertia::render('storefront/about', [
            'page' => [
                'title' => $page->title,
                'body' => $page->body,
                'meta_description' => $page->meta_description,
            ],
        ]);
    }
}
