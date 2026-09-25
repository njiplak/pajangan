<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Str;

class Order extends Model
{
    public const STATUS_PENDING = 'pending';

    public const STATUS_PAID = 'paid';

    public const STATUS_PROCESSING = 'processing';

    public const STATUS_SHIPPED = 'shipped';

    public const STATUS_COMPLETED = 'completed';

    public const STATUS_CANCELLED = 'cancelled';

    public const SOURCE_STOREFRONT = 'storefront';

    public const SOURCE_MANUAL = 'manual';

    public const STATUSES = [
        self::STATUS_PENDING,
        self::STATUS_PAID,
        self::STATUS_PROCESSING,
        self::STATUS_SHIPPED,
        self::STATUS_COMPLETED,
        self::STATUS_CANCELLED,
    ];

    /** Staff-facing names, matching the backoffice order page. */
    public const STATUS_LABELS = [
        self::STATUS_PENDING => 'Menunggu Pembayaran',
        self::STATUS_PAID => 'Dibayar',
        self::STATUS_PROCESSING => 'Diproses',
        self::STATUS_SHIPPED => 'Dikirim',
        self::STATUS_COMPLETED => 'Selesai',
        self::STATUS_CANCELLED => 'Dibatalkan',
    ];

    /**
     * Where staff may move an order next. Forward only: completed and
     * cancelled are final, so released stock can never be sold a second
     * time by reopening, and an unpaid order must be marked paid first.
     */
    public const TRANSITIONS = [
        self::STATUS_PENDING => [self::STATUS_PAID, self::STATUS_CANCELLED],
        self::STATUS_PAID => [self::STATUS_PROCESSING, self::STATUS_SHIPPED, self::STATUS_COMPLETED, self::STATUS_CANCELLED],
        self::STATUS_PROCESSING => [self::STATUS_SHIPPED, self::STATUS_COMPLETED, self::STATUS_CANCELLED],
        self::STATUS_SHIPPED => [self::STATUS_COMPLETED, self::STATUS_CANCELLED],
        self::STATUS_COMPLETED => [],
        self::STATUS_CANCELLED => [],
    ];

    protected $fillable = [
        'customer_id',
        'source',
        'order_number',
        'customer_name',
        'customer_email',
        'customer_phone',
        'shipping_address',
        'shipping_city',
        'shipping_province',
        'shipping_postal_code',
        'shipping_cost',
        'shipping_discount',
        'shipping_area_id',
        'shipping_area_name',
        'courier_code',
        'courier_name',
        'courier_service',
        'courier_etd',
        'tracking_number',
        'biteship_order_id',
        'shipment_status',
        'notes',
        'stock_draw',
        'stock_released_at',
        'status',
        'subtotal',
        'total',
        'payment_gateway',
        'payment_channel',
        'payment_reference',
        'payment_status',
        'admin_fee',
        'fee_borne_by',
        'paid_at',
        'refunded_at',
        'refund_reference',
        'payment_payload',
    ];

    protected function casts(): array
    {
        return [
            'subtotal' => 'integer',
            'total' => 'integer',
            'shipping_cost' => 'integer',
            'shipping_discount' => 'integer',
            'admin_fee' => 'integer',
            'paid_at' => 'datetime',
            'refunded_at' => 'datetime',
            'stock_draw' => 'array',
            'stock_released_at' => 'datetime',
            'payment_payload' => 'array',
        ];
    }

    /**
     * @return list<string>
     */
    public function nextStatuses(): array
    {
        return self::TRANSITIONS[$this->status] ?? [];
    }

    public function canMoveTo(string $status): bool
    {
        return in_array($status, $this->nextStatuses(), true);
    }

    /**
     * Paid for and not yet finished — the only window in which telling the
     * customer about a shipment is true.
     */
    public function isInFulfillment(): bool
    {
        return in_array($this->status, [self::STATUS_PAID, self::STATUS_PROCESSING, self::STATUS_SHIPPED], true);
    }

    public static function generateNumber(): string
    {
        do {
            $candidate = 'ORD-'.now()->format('Ymd').'-'.Str::upper(Str::random(6));
        } while (static::query()->where('order_number', $candidate)->exists());

        return $candidate;
    }

    public static function statusLabel(string $status): string
    {
        return self::STATUS_LABELS[$status] ?? $status;
    }

    /**
     * Paid for and not yet handed to a courier — the only time booking a
     * shipment makes sense.
     */
    public function awaitsShipment(): bool
    {
        return in_array($this->status, [self::STATUS_PAID, self::STATUS_PROCESSING], true);
    }

    /**
     * Cancelled after the money arrived, and not yet paid back.
     */
    public function owesRefund(): bool
    {
        return $this->status === self::STATUS_CANCELLED
            && $this->paid_at !== null
            && $this->refunded_at === null;
    }

    public function items()
    {
        return $this->hasMany(OrderItem::class);
    }

    public function activities()
    {
        return $this->hasMany(OrderActivity::class);
    }

    public function customer()
    {
        return $this->belongsTo(Customer::class);
    }
}
