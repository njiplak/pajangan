<?php

namespace App\Http\Controllers\Storefront;

use App\Http\Controllers\Controller;
use App\Http\Requests\ShippingDestinationRequest;
use App\Http\Requests\ShippingEstimateRequest;
use App\Http\Requests\StorefrontShippingRateRequest;
use App\Models\Product;
use App\Service\Cart\CartService;
use App\Service\Shipping\ShippingDestination;
use App\Service\Shipping\ShippingEstimator;
use App\Service\Shipping\ShippingProviderManager;
use Illuminate\Http\Request;
use RuntimeException;

class ShippingController extends Controller
{
    public function __construct(
        private readonly CartService $cart,
        private readonly ShippingProviderManager $shipping,
        private readonly ShippingDestination $destination,
        private readonly ShippingEstimator $estimator,
    ) {}

    /**
     * Remember where this visitor wants their order sent, so they are asked
     * once instead of on every product page and again at checkout.
     */
    public function setDestination(ShippingDestinationRequest $request)
    {
        $this->destination->set(
            $request->validated('destination_area_id'),
            $request->validated('destination_area_name'),
        );

        return back();
    }

    /**
     * Cheapest rate for one product, or for the whole cart when no product
     * is named. Returns a null estimate rather than an error whenever we
     * cannot quote — the page renders fine without one.
     */
    public function estimate(ShippingEstimateRequest $request)
    {
        $destination = $this->destination->get();

        if (! $destination) {
            return response()->json(['destination' => null, 'estimate' => null]);
        }

        [$weightGram, $itemValue] = $this->shipmentFor($request);

        if ($weightGram < 1) {
            return response()->json(['destination' => $destination, 'estimate' => null]);
        }

        return response()->json([
            'destination' => $destination,
            'estimate' => $this->estimator->cheapest($destination['id'], $weightGram, $itemValue),
        ]);
    }

    /**
     * @return array{0: int, 1: int} [weight in grams, declared value]
     */
    private function shipmentFor(ShippingEstimateRequest $request): array
    {
        $productId = $request->validated('product_id');

        if (! $productId) {
            return [$this->cart->totalWeightGrams(), $this->cart->summary()['subtotal']];
        }

        $product = Product::query()->sellable()->find($productId);

        if (! $product || ! $product->is_active) {
            return [0, 0];
        }

        $quantity = (int) ($request->validated('quantity') ?? 1);

        return [
            $product->shippingWeightGram() * $quantity,
            $product->effectivePrice() * $quantity,
        ];
    }

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
