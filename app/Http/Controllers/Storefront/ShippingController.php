<?php

namespace App\Http\Controllers\Storefront;

use App\Http\Controllers\Controller;
use App\Http\Requests\StorefrontShippingRateRequest;
use App\Service\Cart\CartService;
use App\Service\Shipping\ShippingProviderManager;
use Illuminate\Http\Request;
use RuntimeException;

class ShippingController extends Controller
{
    public function __construct(
        private readonly CartService $cart,
        private readonly ShippingProviderManager $shipping,
    ) {}

    public function searchAreas(Request $request)
    {
        $query = (string) $request->query('q', '');

        if (mb_strlen($query) < 3) {
            return response()->json(['areas' => []]);
        }

        try {
            $areas = $this->shipping->resolve('biteship')->searchAreas($query);

            return response()->json(['areas' => $areas]);
        } catch (RuntimeException $e) {
            return response()->json(['message' => $e->getMessage()], 422);
        }
    }

    public function rates(StorefrontShippingRateRequest $request)
    {
        $summary = $this->cart->summary();

        if (empty($summary['items'])) {
            return response()->json(['message' => 'Keranjang belanja Anda kosong.'], 422);
        }

        try {
            $rates = $this->shipping->resolve('biteship')->quoteRates([
                'destination_area_id' => $request->validated('destination_area_id'),
                'weight_gram' => $this->cart->totalWeightGrams(),
                'item_value' => $summary['subtotal'],
            ]);

            return response()->json(['rates' => $rates]);
        } catch (RuntimeException $e) {
            return response()->json(['message' => $e->getMessage()], 422);
        }
    }
}
