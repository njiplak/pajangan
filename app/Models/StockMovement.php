<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class StockMovement extends Model
{
    public const REASON_INITIAL = 'initial';

    public const REASON_CHECKOUT = 'checkout';

    public const REASON_MANUAL_ORDER = 'manual_order';

    public const REASON_RELEASE = 'release';

    public const REASON_ADJUSTMENT = 'adjustment';

    protected $fillable = [
        'product_id',
        'order_id',
        'user_id',
        'change',
        'stock_after',
        'reason',
        'note',
    ];

    protected function casts(): array
    {
        return [
            'change' => 'integer',
            'stock_after' => 'integer',
        ];
    }

    public function product()
    {
        return $this->belongsTo(Product::class);
    }

    public function order()
    {
        return $this->belongsTo(Order::class);
    }

    public function user()
    {
        return $this->belongsTo(User::class);
    }
}
