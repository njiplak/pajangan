<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class ProductReview extends Model
{
    protected $fillable = [
        'product_id',
        'customer_id',
        'order_id',
        'rating',
        'body',
        'is_visible',
    ];

    protected function casts(): array
    {
        return [
            'rating' => 'integer',
            'is_visible' => 'boolean',
        ];
    }

    public function product()
    {
        return $this->belongsTo(Product::class);
    }

    public function customer()
    {
        return $this->belongsTo(Customer::class);
    }

    /**
     * "Yohana W." — enough to feel like a real person, not enough to
     * identify a customer from a public product page.
     */
    public function authorName(): string
    {
        $parts = preg_split('/\s+/', trim((string) $this->customer?->name)) ?: [];
        $first = $parts[0] ?? '';

        if ($first === '') {
            return 'Pembeli';
        }

        $initial = isset($parts[1]) ? ' '.mb_strtoupper(mb_substr($parts[1], 0, 1)).'.' : '';

        return $first.$initial;
    }
}
