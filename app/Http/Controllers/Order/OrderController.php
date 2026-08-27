<?php

namespace App\Http\Controllers\Order;

use App\Contract\Order\OrderContract;
use App\Contract\Setting\SettingContract;
use App\Http\Controllers\Controller;
use App\Http\Requests\CancelShipmentRequest;
use App\Http\Requests\CreateShipmentRequest;
use App\Http\Requests\OrderShippingRequest;
use App\Http\Requests\OrderStatusRequest;
use App\Http\Requests\ShippingRateRequest;
use App\Models\Order;
use App\Service\Shipping\ShippingProviderManager;
use App\Utils\WebResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use RuntimeException;

class OrderController extends Controller
{
    protected OrderContract $service;

    public function __construct(
        OrderContract $service,
        private readonly ShippingProviderManager $shipping,
        private readonly SettingContract $settings,
    ) {
        $this->service = $service;
    }

    public function index()
    {
        return Inertia::render('order/index');
    }

    public function fetch()
    {
        $data = $this->service->all(
            allowedFilters: ['status', 'order_number', 'customer_name'],
            allowedSorts: ['created_at', 'total'],
            withPaginate: true,
            perPage: request()->get('per_page', 10),
            orderColumn: 'created_at',
            orderPosition: 'desc',
        );

        return response()->json($data);
    }

    public function show($id)
    {
        $order = $this->service->find($id, ['items']);

        return Inertia::render('order/show', [
            'order' => $order,
            'statuses' => Order::STATUSES,
            'preferredCollectionMethod' => $this->settings->allAsKeyValue()['shipping_preferred_collection_method'] ?? null,
        ]);
    }

    public function updateStatus(OrderStatusRequest $request, $id)
    {
        $data = $this->service->updateStatus((int) $id, $request->validated('status'));

        return WebResponse::response($data);
    }

    public function searchShippingAreas(Request $request)
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

    public function quoteShippingRates(ShippingRateRequest $request, $id)
    {
        $order = $this->service->find((int) $id);

        if ($order instanceof \Exception) {
            return response()->json(['message' => 'Pesanan tidak ditemukan.'], 404);
        }

        try {
            $rates = $this->shipping->resolve('biteship')->quoteRates([
                'destination_area_id' => $request->validated('destination_area_id'),
                'weight_gram' => $request->validated('weight_gram'),
                'item_value' => $order->subtotal,
            ]);

            return response()->json(['rates' => $rates]);
        } catch (RuntimeException $e) {
            return response()->json(['message' => $e->getMessage()], 422);
        }
    }

    public function updateShipping(OrderShippingRequest $request, $id)
    {
        $data = $this->service->updateShipping((int) $id, $request->validated());

        return WebResponse::response($data);
    }

    /**
     * Actually creates the shipment at Biteship — unlike quoteShippingRates
     * and updateShipping, this has a real-world/cost effect (a courier
     * pickup is requested or a drop-off waybill is issued), so it's a
     * distinct action from the free record-keeping save above, and is
     * refused if this order already has one.
     */
    public function createShipment(CreateShipmentRequest $request, $id)
    {
        $order = $this->service->find((int) $id);

        if ($order instanceof \Exception) {
            return back()->withErrors(['errors' => 'Pesanan tidak ditemukan.']);
        }

        if ($order->biteship_order_id) {
            return back()->withErrors(['errors' => 'Pengiriman untuk pesanan ini sudah dibuat di Biteship.']);
        }

        try {
            $shipment = $this->shipping->resolve('biteship')->createShipment([
                'destination_area_id' => $request->validated('destination_area_id'),
                'destination_contact_name' => $order->customer_name,
                'destination_contact_phone' => $order->customer_phone,
                'destination_address' => $order->shipping_address,
                'weight_gram' => $request->validated('weight_gram'),
                'item_value' => $order->subtotal,
                'courier_code' => $request->validated('courier_code'),
                'courier_service_code' => $request->validated('courier_service_code'),
            ]);
        } catch (RuntimeException $e) {
            return back()->withErrors(['errors' => $e->getMessage()]);
        }

        $data = $this->service->updateShipping((int) $id, [
            'biteship_order_id' => $shipment['provider_order_id'],
            'tracking_number' => $shipment['tracking_number'],
            'courier_code' => $shipment['courier_code'],
            'courier_name' => $shipment['courier_name'],
            'courier_service' => $shipment['courier_service'],
            'shipping_cost' => $shipment['price'] ?? $order->shipping_cost,
            'shipping_area_id' => $request->validated('destination_area_id'),
            'shipping_area_name' => $request->validated('destination_area_name') ?? $order->shipping_area_name,
        ]);

        return WebResponse::response($data);
    }

    /**
     * Cancels the shipment at Biteship and clears the order's shipment
     * fields so staff can create a fresh one with a different courier.
     * The destination area/name are kept — the address itself hasn't
     * changed, only the courier choice is being redone.
     */
    public function cancelShipment(CancelShipmentRequest $request, $id)
    {
        $order = $this->service->find((int) $id);

        if ($order instanceof \Exception) {
            return back()->withErrors(['errors' => 'Pesanan tidak ditemukan.']);
        }

        if (! $order->biteship_order_id) {
            return back()->withErrors(['errors' => 'Pesanan ini belum punya pengiriman di Biteship untuk dibatalkan.']);
        }

        try {
            $this->shipping->resolve('biteship')->cancelShipment(
                $order->biteship_order_id,
                $request->validated('cancellation_reason_code'),
                $request->validated('cancellation_reason'),
            );
        } catch (RuntimeException $e) {
            return back()->withErrors(['errors' => $e->getMessage()]);
        }

        $data = $this->service->updateShipping((int) $id, [
            'biteship_order_id' => null,
            'tracking_number' => null,
            'courier_code' => null,
            'courier_name' => null,
            'courier_service' => null,
            'courier_etd' => null,
            'shipping_cost' => null,
        ]);

        return WebResponse::response($data);
    }
}
