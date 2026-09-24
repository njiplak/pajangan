<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Str;
use Spatie\MediaLibrary\HasMedia;
use Spatie\MediaLibrary\InteractsWithMedia;

/**
 * A UMKM producer. Products keep producer_name/producer_region as a cache
 * of this row, maintained by ProducerService and ProductService.
 */
class Producer extends Model implements HasMedia
{
    use InteractsWithMedia;

    protected $fillable = [
        'name',
        'slug',
        'region',
        'story',
        'is_active',
    ];

    protected function casts(): array
    {
        return [
            'is_active' => 'boolean',
        ];
    }

    protected static function booted(): void
    {
        static::saving(function (Producer $producer) {
            if (empty($producer->slug) || $producer->isDirty('name')) {
                $producer->slug = static::uniqueSlug($producer->name, $producer->id);
            }
        });
    }

    public static function uniqueSlug(string $name, ?int $ignoreId = null): string
    {
        $base = Str::slug($name) ?: 'produsen';
        $slug = $base;

        for ($i = 2; static::query()
            ->where('slug', $slug)
            ->when($ignoreId, fn ($query) => $query->where('id', '!=', $ignoreId))
            ->exists(); $i++) {
            $slug = "{$base}-{$i}";
        }

        return $slug;
    }

    public function products()
    {
        return $this->hasMany(Product::class);
    }
}
