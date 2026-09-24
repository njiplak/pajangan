<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class Order extends Model
{
    public const STATUS_PENDING = 'pending';

    public const STATUS_PAID = 'paid';

    public const STATUS_PROCESSING = 'processing';

    public const STATUS_SHIPPED = 'shipped';

    public const STATUS_COMPLETED = 'completed';

    public const STATUS_CANCELLED = 'cancelled';

    public const STATUSES = [
        self::STATUS_PENDING,
        self::STATUS_PAID,
        self::STATUS_PROCESSING,
        self::STATUS_SHIPPED,
        self::STATUS_COMPLETED,
        self::STATUS_CANCELLED,
    ];

    protected $fillable = [
        'customer_id',
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
            'stock_draw' => 'array',
            'stock_released_at' => 'datetime',
            'payment_payload' => 'array',
        ];
    }

    public function items()
    {
        return $this->hasMany(OrderItem::class);
    }

    public function customer()
    {
        return $this->belongsTo(Customer::class);
    }
}
