<?php

namespace App\Http\Controllers\Review;

use App\Http\Controllers\Controller;
use App\Models\ProductReview;
use Illuminate\Http\Request;
use Inertia\Inertia;

/**
 * Staff moderation of storefront reviews. Gated on the product
 * permissions, since reviews are product content — which also means every
 * existing role that manages products can moderate without a new
 * permission being seeded.
 */
class ReviewController extends Controller
{
    public function index(Request $request)
    {
        $filter = $request->query('status');

        $reviews = ProductReview::query()
            ->with(['product:id,name,slug', 'customer:id,name,email'])
            ->when($filter === 'hidden', fn ($query) => $query->where('is_visible', false))
            ->when($filter === 'visible', fn ($query) => $query->where('is_visible', true))
            ->latest()
            ->paginate(20)
            ->withQueryString();

        return Inertia::render('review/index', [
            'reviews' => $reviews->through(fn (ProductReview $review) => [
                'id' => $review->id,
                'rating' => $review->rating,
                'body' => $review->body,
                'is_visible' => $review->is_visible,
                'created_at' => $review->created_at?->toIso8601String(),
                'product' => $review->product?->only(['id', 'name', 'slug']),
                // Staff see the full name and email; the storefront does not.
                'customer' => $review->customer?->only(['name', 'email']),
            ]),
            'filter' => $filter,
        ]);
    }

    public function visibility(Request $request, int $id)
    {
        $validated = $request->validate(['is_visible' => ['required', 'boolean']]);

        ProductReview::query()->findOrFail($id)->update(['is_visible' => $validated['is_visible']]);

        return back();
    }
}
