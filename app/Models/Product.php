<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Str;
use Spatie\MediaLibrary\HasMedia;
use Spatie\MediaLibrary\InteractsWithMedia;

class Product extends Model implements HasMedia
{
    use InteractsWithMedia;

    protected $fillable = [
        'name',
        'slug',
        'description',
        'price',
        'discount_percent',
        'stock',
        'weight_gram',
        'producer_id',
        'producer_name',
        'producer_region',
        'is_active',
        'is_bundle',
    ];

    protected function casts(): array
    {
        return [
            'price' => 'integer',
            'discount_percent' => 'integer',
            'stock' => 'integer',
            'weight_gram' => 'integer',
            'is_active' => 'boolean',
            'is_bundle' => 'boolean',
        ];
    }

    public function effectivePrice(): int
    {
        return $this->discount_percent
            ? (int) round($this->price * (1 - $this->discount_percent / 100))
            : $this->price;
    }

    /**
     * Units a customer may actually buy right now.
     *
     * A bundle owns no stock of its own: it is limited by whichever
     * component runs out first, so selling one bundle draws down the same
     * rows the component's own listing sells from. Callers that need this
     * under a lock must set the `bundleItems.product` relation from their
     * locked rows first, otherwise the components are re-read unlocked.
     */
    public function availableStock(): int
    {
        if (! $this->is_bundle) {
            return max(0, (int) $this->stock);
        }

        $items = $this->bundleItems;

        // An empty bundle is a configuration mistake, not free inventory.
        if ($items->isEmpty()) {
            return 0;
        }

        $limits = $items->map(function (BundleItem $item) {
            $component = $item->product;

            if (! $component || ! $component->is_active || $item->quantity < 1) {
                return 0;
            }

            return intdiv(max(0, (int) $component->stock), $item->quantity);
        });

        return (int) $limits->min();
    }

    /**
     * Weight used for courier quoting. A bundle ships as its contents.
     */
    public function shippingWeightGram(): int
    {
        if (! $this->is_bundle) {
            return (int) $this->weight_gram;
        }

        return (int) $this->bundleItems->sum(
            fn (BundleItem $item) => (int) ($item->product?->weight_gram ?? 0) * $item->quantity
        );
    }

    /**
     * What the contents would cost bought separately, so the storefront can
     * show the saving. Zero for a non-bundle.
     */
    public function componentsTotal(): int
    {
        if (! $this->is_bundle) {
            return 0;
        }

        return (int) $this->bundleItems->sum(
            fn (BundleItem $item) => (int) ($item->product?->effectivePrice() ?? 0) * $item->quantity
        );
    }

    protected static function booted(): void
    {
        static::saving(function (Product $product) {
            if (empty($product->slug) || $product->isDirty('name')) {
                $product->slug = static::generateUniqueSlug($product->name, $product->id);
            }
        });
    }

    public static function generateUniqueSlug(string $name, ?int $ignoreId = null): string
    {
        $base = Str::slug($name) ?: 'produk';
        $slug = $base;
        $suffix = 1;

        while (
            static::query()
                ->where('slug', $slug)
                ->when($ignoreId, fn ($query) => $query->where('id', '!=', $ignoreId))
                ->exists()
        ) {
            $slug = "{$base}-{$suffix}";
            $suffix++;
        }

        return $slug;
    }

    public function orderItems()
    {
        return $this->hasMany(OrderItem::class);
    }

    /**
     * Components this product is made of, when it is a bundle.
     */
    public function bundleItems()
    {
        return $this->hasMany(BundleItem::class, 'bundle_id');
    }

    /**
     * Rows pointing at this product as a component of some bundle.
     */
    public function bundleMemberships()
    {
        return $this->hasMany(BundleItem::class, 'product_id');
    }

    public function reviews()
    {
        return $this->hasMany(ProductReview::class);
    }

    public function producer()
    {
        return $this->belongsTo(Producer::class);
    }

    /**
     * Adds `rating_avg` and `rating_count` over published reviews only.
     */
    public function scopeWithRating($query)
    {
        $visible = fn ($reviews) => $reviews->where('is_visible', true);

        return $query
            ->withAvg(['reviews as rating_avg' => $visible], 'rating')
            ->withCount(['reviews as rating_count' => $visible]);
    }

    public function scopeSellable($query)
    {
        return $query->with('bundleItems.product');
    }
}
