<?php

namespace Modules\Certificates\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Casts\Attribute;
use Illuminate\Database\Eloquent\Model;
use Spatie\MediaLibrary\HasMedia;
use Spatie\MediaLibrary\InteractsWithMedia;
use Spatie\MediaLibrary\MediaCollections\Models\Media;

class Certificate extends Model implements HasMedia
{
    use InteractsWithMedia;

    protected $fillable = [
        'name',
        'issuer',
        'sort_order',
        'is_active',
    ];

    /**
     * Match the database defaults so fresh models serialize correctly.
     */
    protected $attributes = [
        'sort_order' => 0,
        'is_active' => true,
    ];

    protected function casts(): array
    {
        return [
            'sort_order' => 'integer',
            'is_active' => 'boolean',
        ];
    }

    /*
    |--------------------------------------------------------------------------
    | Media
    |--------------------------------------------------------------------------
    */

    public function registerMediaCollections(): void
    {
        $this->addMediaCollection('image')
            ->singleFile()
            ->acceptsMimeTypes(['image/jpeg', 'image/png', 'image/webp']);
    }

    public function registerMediaConversions(?Media $media = null): void
    {
        $this->addMediaConversion('thumb')
            ->width(200)
            ->height(200)
            ->nonQueued();
    }

    /*
    |--------------------------------------------------------------------------
    | Helpers
    |--------------------------------------------------------------------------
    */

    public function scopeActive(Builder $query): Builder
    {
        return $query->where('is_active', true);
    }

    protected function imageUrl(): Attribute
    {
        return Attribute::get(fn (): ?string => $this->getFirstMediaUrl('image') ?: null);
    }

    protected function imageThumbUrl(): Attribute
    {
        return Attribute::get(fn (): ?string => $this->getFirstMediaUrl('image', 'thumb') ?: null);
    }
}
