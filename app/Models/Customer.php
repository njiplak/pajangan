<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Foundation\Auth\User as Authenticatable;

/**
 * A shopper. Authenticates on the `customer` guard, never the staff one.
 */
class Customer extends Authenticatable
{
    protected $fillable = [
        'name',
        'email',
        'google_id',
        'avatar_url',
        'phone',
        'email_verified_at',
    ];

    protected $hidden = [
        'remember_token',
    ];

    protected function casts(): array
    {
        return [
            'email_verified_at' => 'datetime',
        ];
    }

    public function orders()
    {
        return $this->hasMany(Order::class);
    }

    public function addresses()
    {
        return $this->hasMany(CustomerAddress::class);
    }

    public function wishlist()
    {
        return $this->hasMany(WishlistItem::class);
    }

    /**
     * Orders placed on this account, plus guest orders placed under the
     * same address — but the latter only once Google has verified the
     * address, since otherwise anyone could type someone else's email.
     *
     * @return Builder<Order>
     */
    public function visibleOrders(): Builder
    {
        return Order::query()->where(function (Builder $query) {
            $query->where('customer_id', $this->id);

            if ($this->email_verified_at) {
                $query->orWhere(function (Builder $guest) {
                    $guest->whereNull('customer_id')
                        ->whereRaw('LOWER(customer_email) = ?', [mb_strtolower($this->email)]);
                });
            }
        });
    }
}
