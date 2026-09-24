<?php

namespace App\Http\Controllers\Customer;

use App\Http\Controllers\Controller;
use App\Models\Customer;
use App\Models\Product;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;

class ReviewController extends Controller
{
    public function store(Request $request, Product $product)
    {
        $validated = $request->validate([
            'rating' => ['required', 'integer', 'between:1,5'],
            'body' => ['nullable', 'string', 'max:2000'],
        ]);

        $customer = $this->customer();
        $order = $customer->completedOrderContaining($product);

        if (! $order) {
            // Reviews are only credible if every one comes from someone who
            // actually received the product.
            return back()->withErrors([
                'review' => 'Ulasan bisa diberikan setelah pesanan berisi produk ini sampai.',
            ]);
        }

        $review = $customer->reviews()->firstOrNew(['product_id' => $product->id]);

        // is_visible is left alone on edit: a customer must not be able to
        // republish a review staff have hidden.
        $review->fill([
            'order_id' => $order->id,
            'rating' => $validated['rating'],
            'body' => $validated['body'] ?? null,
        ])->save();

        return back();
    }

    public function destroy(Product $product)
    {
        $this->customer()->reviews()->where('product_id', $product->id)->delete();

        return back();
    }

    private function customer(): Customer
    {
        /** @var Customer */
        return Auth::guard('customer')->user();
    }
}
