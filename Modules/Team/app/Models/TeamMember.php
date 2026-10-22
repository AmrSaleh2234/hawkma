<?php

namespace Modules\Team\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Casts\Attribute;
use Illuminate\Database\Eloquent\Model;
use Spatie\MediaLibrary\HasMedia;
use Spatie\MediaLibrary\InteractsWithMedia;
use Spatie\MediaLibrary\MediaCollections\Models\Media;

class TeamMember extends Model implements HasMedia
{
    use InteractsWithMedia;

    protected $fillable = [
        'name',
        'job_title',
        'experience_years',
        'sort_order',
        'is_active',
    ];

    /**
     * Match the database defaults so fresh models serialize correctly.
     */
    protected $attributes = [
        'experience_years' => 0,
        'sort_order' => 0,
        'is_active' => true,
    ];

    protected function casts(): array
    {
        return [
            'experience_years' => 'integer',
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
        $this->addMediaCollection('avatar')
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

    protected function avatarUrl(): Attribute
    {
        return Attribute::get(fn (): ?string => $this->getFirstMediaUrl('avatar') ?: null);
    }

    protected function avatarThumbUrl(): Attribute
    {
        return Attribute::get(fn (): ?string => $this->getFirstMediaUrl('avatar', 'thumb') ?: null);
    }
}
