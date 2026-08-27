<?php

namespace App\Http\Controllers\Storefront;

use App\Http\Controllers\Controller;
use App\Service\Cart\CartService;
use Illuminate\Http\Request;
use Inertia\Inertia;

class CartController extends Controller
{
    public function __construct(private readonly CartService $cart) {}

    public function index()
    {
        return Inertia::render('storefront/cart/index', [
            'cart' => $this->cart->summary(),
        ]);
    }

    public function store(Request $request)
    {
        $validated = $request->validate([
            'product_id' => ['required', 'integer', 'exists:products,id'],
            'quantity' => ['required', 'integer', 'min:1'],
        ]);

        $this->cart->add((int) $validated['product_id'], (int) $validated['quantity']);

        return redirect()->back();
    }

    public function update(Request $request, int $productId)
    {
        $validated = $request->validate([
            'quantity' => ['required', 'integer', 'min:0'],
        ]);

        $this->cart->updateQuantity($productId, (int) $validated['quantity']);

        return redirect()->back();
    }

    public function destroy(int $productId)
    {
        $this->cart->remove($productId);

        return redirect()->back();
    }
}
