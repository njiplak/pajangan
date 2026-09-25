<?php

namespace App\Http\Controllers\Order;

use App\Contract\Notification\OrderNotifierContract;
use App\Contract\Order\OrderContract;
use App\Contract\Payment\PaymentStatus;
use App\Contract\Setting\SettingContract;
use App\Http\Controllers\Controller;
use App\Http\Requests\CancelShipmentRequest;
use App\Http\Requests\CreateShipmentRequest;
use App\Http\Requests\ManualOrderRequest;
use App\Http\Requests\OrderDetailsRequest;
use App\Http\Requests\OrderShippingRequest;
use App\Http\Requests\OrderStatusRequest;
use App\Http\Requests\ShippingRateRequest;
use App\Models\Order;
use App\Models\OrderActivity;
use App\Models\Product;
use App\Service\Order\ManualOrderService;
use App\Service\Order\OrderActivityLog;
use App\Service\Order\UnpaidHoldWindow;
use App\Service\Payment\PaymentReconciler;
use App\Service\Shipping\ShippingProviderManager;
use App\Utils\ListFilter;
use App\Utils\WebResponse;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\Request;
use Inertia\Inertia;
use RuntimeException;
use Spatie\QueryBuilder\AllowedFilter;
use Spatie\QueryBuilder\QueryBuilder;
use Throwable;

class OrderController extends Controller
{
    protected OrderContract $service;

    public function __construct(
        OrderContract $service,
        private readonly ShippingProviderManager $shipping,
        private readonly OrderNotifierContract $notifier,
        private readonly SettingContract $settings,
        private readonly PaymentReconciler $reconciler,
        private readonly OrderActivityLog $activity,
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
            allowedFilters: self::listFilters(),
            allowedSorts: ['created_at', 'total'],
            withPaginate: true,
            perPage: request()->get('per_page', 10),
            orderColumn: 'created_at',
            orderPosition: 'desc',
        );

        return response()->json($data);
    }

    /**
     * @return list<string|AllowedFilter>
     */
    private static function listFilters(): array
    {
        return [
            ListFilter::search(['order_number', 'customer_name', 'customer_email', 'customer_phone']),
            AllowedFilter::exact('status'),
            'order_number',
            'customer_name',
            AllowedFilter::callback('created_from', fn (Builder $query, $value) => self::whereDateBound($query, '>=', $value)),
            AllowedFilter::callback('created_to', fn (Builder $query, $value) => self::whereDateBound($query, '<=', $value)),
        ];
    }

    private static function whereDateBound(Builder $query, string $operator, mixed $value): void
    {
        // A malformed date would reach the database as-is and fail the whole list.
        if (is_string($value) && preg_match('/^\d{4}-\d{2}-\d{2}$/', $value) && strtotime($value) !== false) {
            $query->whereDate('created_at', $operator, $value);
        }
    }

    public function show($id)
    {
        $order = $this->service->find($id, ['items']);

        // find() reports a missing record by returning the exception.
        if ($order instanceof \Exception) {
            abort(404);
        }

        return Inertia::render('order/show', [
            'order' => $order,
            'nextStatuses' => $order->nextStatuses(),
            'canCheckPayment' => $this->reconciler->canCheck($order)
                && ! in_array($order->payment_status, [PaymentStatus::PAID, PaymentStatus::REFUNDED], true),
            'canRefund' => $order->owesRefund(),
            'canUpdate' => (bool) request()->user()?->can('order.update'),
            'activities' => $order->activities()->with('user:id,name')->latest('id')->get()
                ->map(fn (OrderActivity $activity) => [
                    'id' => $activity->id,
                    'action' => $activity->action,
                    'description' => $activity->description,
                    'user_name' => $activity->user?->name,
                    'created_at' => $activity->created_at?->toIso8601String(),
                ])->all(),
            'preferredCollectionMethod' => $this->settings->allAsKeyValue()['shipping_preferred_collection_method'] ?? null,
        ]);
    }

    public function create(UnpaidHoldWindow $window)
    {
        return Inertia::render('order/create', [
            'manualHoldHours' => $window->manualHours(),
            'productOptions' => Product::query()
                ->where('is_active', true)
                ->with('bundleItems.product')
                ->orderBy('name')
                ->get()
                ->map(fn (Product $product) => [
                    'id' => $product->id,
                    'name' => $product->name,
                    'price' => $product->effectivePrice(),
                    'available' => $product->availableStock(),
                    'is_bundle' => $product->is_bundle,
                ])->values(),
        ]);
    }

    public function store(ManualOrderRequest $request, ManualOrderService $manualOrders)
    {
        $order = $manualOrders->create($request->validated(), auth('web')->id());

        return redirect()->route('backoffice.order.show', $order->id);
    }

    public function updateDetails(OrderDetailsRequest $request, $id)
    {
        return WebResponse::response($this->service->updateDetails((int) $id, $request->validated()));
    }

    public function print($id)
    {
        $order = $this->service->find((int) $id, ['items']);

        if ($order instanceof \Exception) {
            abort(404);
        }

        return view('backoffice.order-print', ['order' => $order]);
    }

    private const EXPORT_COLUMNS = [
        'order_number', 'created_at', 'status', 'customer_name', 'customer_email', 'customer_phone',
        'shipping_city', 'shipping_province', 'subtotal', 'shipping_cost', 'shipping_discount', 'admin_fee',
        'total', 'payment_gateway', 'payment_status', 'paid_at', 'courier_name', 'courier_service',
        'tracking_number', 'refunded_at', 'refund_reference',
    ];

    public function export()
    {
        $query = QueryBuilder::for(Order::class)
            ->allowedFilters(self::listFilters())
            ->orderByDesc('created_at')
            ->orderByDesc('id');

        return response()->streamDownload(function () use ($query) {
            $out = fopen('php://output', 'w');
            fputcsv($out, self::EXPORT_COLUMNS);

            $query->chunk(500, function ($orders) use ($out) {
                foreach ($orders as $order) {
                    fputcsv($out, array_map(
                        fn (string $column) => self::csvCell($order->{$column}),
                        self::EXPORT_COLUMNS,
                    ));
                }
            });

            fclose($out);
        }, 'pesanan-'.now()->format('Ymd-His').'.csv', ['Content-Type' => 'text/csv; charset=UTF-8']);
    }

    /**
     * Spreadsheets run a cell starting with = + - @ as a formula, and these
     * cells hold text customers typed.
     */
    private static function csvCell(mixed $value): string|int
    {
        if ($value instanceof \DateTimeInterface) {
            return $value->format('Y-m-d H:i:s');
        }

        if (is_int($value)) {
            return $value;
        }

        $value = (string) $value;

        return preg_match('/^[=+\-@\t\r]/', $value) ? "'".$value : $value;
    }

    public function addNote(Request $request, $id)
    {
        $validated = $request->validate(['body' => ['required', 'string', 'max:2000']]);

        return WebResponse::response($this->service->addNote((int) $id, trim($validated['body'])));
    }

    public function refund(Request $request, $id)
    {
        $validated = $request->validate(['refund_reference' => ['nullable', 'string', 'max:255']]);

        return WebResponse::response($this->service->recordRefund((int) $id, $validated['refund_reference'] ?? null));
    }

    public function updateStatus(OrderStatusRequest $request, $id)
    {
        $data = $this->service->updateStatus((int) $id, $request->validated('status'));

        return WebResponse::response($data);
    }

    public function checkPayment($id)
    {
        $order = $this->service->find((int) $id);

        if ($order instanceof \Exception) {
            return WebResponse::response($order);
        }

        try {
            $this->reconciler->check($order);
        } catch (Throwable $e) {
            $this->activity->record($order, 'payment_check', "Cek pembayaran ke {$order->payment_gateway} gagal: {$e->getMessage()}", auth('web')->id());

            return back()->withErrors(['errors' => 'Gagal mengecek pembayaran: '.$e->getMessage()]);
        }

        $this->activity->record(
            $order,
            'payment_check',
            "Cek pembayaran ke {$order->payment_gateway}: status {$order->fresh()->payment_status}.",
            auth('web')->id(),
        );

        return back();
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
        $before = $this->service->find((int) $id);

        if ($before instanceof \Exception) {
            return WebResponse::response($before);
        }

        $validated = $request->validated();
        $courier = trim(($validated['courier_name'] ?? $validated['courier_code']).' '.$validated['courier_service']);
        $data = $this->service->updateShipping((int) $id, $validated, [
            'shipping',
            "Pengiriman disimpan: {$courier}, ongkir Rp ".number_format((int) $validated['shipping_cost'], 0, ',', '.')
                .(filled($validated['tracking_number'] ?? null) ? ", resi {$validated['tracking_number']}" : '').'.',
        ]);

        // Before the order is shipped, the number rides along with the
        // shipped email instead. After, a new or corrected one is re-sent.
        if (! $data instanceof \Exception
            && $data->status === Order::STATUS_SHIPPED
            && filled($data->tracking_number)
            && $data->tracking_number !== $before->tracking_number) {
            $this->notifier->orderShipped($data);
        }

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

        // Booking a courier costs Biteship balance and emails the customer
        // "on its way"; neither is right for an unpaid, sent or dead order.
        if (! $order->awaitsShipment()) {
            return back()->withErrors(['errors' => 'Pengiriman hanya bisa dibuat untuk pesanan yang sudah dibayar dan belum dikirim (status sekarang: '
                .Order::statusLabel($order->status).').']);
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
        ], [
            'shipment_created',
            "Pengiriman Biteship dibuat: {$shipment['courier_name']} {$shipment['courier_service']}"
                .($shipment['tracking_number'] ? ", resi {$shipment['tracking_number']}" : ', resi belum ada').'.',
        ]);

        // Only once the shipment record saved — updateShipping reports a
        // failure by returning the exception rather than throwing.
        if (! $data instanceof \Exception) {
            $this->notifier->orderShipped($data);
        }

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

        // With the parcel no longer at a courier, "shipped" would be false
        // and would block booking a new shipment.
        $backToProcessing = $order->status === Order::STATUS_SHIPPED;

        $data = $this->service->updateShipping((int) $id, [
            'biteship_order_id' => null,
            'tracking_number' => null,
            'courier_code' => null,
            'courier_name' => null,
            'courier_service' => null,
            'courier_etd' => null,
            'shipping_cost' => null,
            // The old shipment's last courier status must not be compared
            // against the next shipment's, or its first report is missed.
            'shipment_status' => null,
            ...($backToProcessing ? ['status' => Order::STATUS_PROCESSING] : []),
        ], [
            'shipment_cancelled',
            'Pengiriman Biteship dibatalkan (alasan: '
                .($request->validated('cancellation_reason') ?: $request->validated('cancellation_reason_code')).')'
                .($order->tracking_number ? ", resi {$order->tracking_number} tidak berlaku" : '')
                .($backToProcessing ? '. Status: '.Order::statusLabel(Order::STATUS_SHIPPED).' → '.Order::statusLabel(Order::STATUS_PROCESSING) : '')
                .'.',
        ]);

        // A cancelled order's customer already has the cancellation email.
        if (! $data instanceof \Exception && $data->status !== Order::STATUS_CANCELLED) {
            $this->notifier->orderShipmentCancelled($data, $order->tracking_number);
        }

        return WebResponse::response($data);
    }
}
