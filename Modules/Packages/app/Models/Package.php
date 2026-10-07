<?php

namespace Modules\Packages\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;
use Modules\Packages\Database\Factories\PackageFactory;
use Modules\Packages\Enums\SubscriptionStatus;

class Package extends Model
{
    use HasFactory, SoftDeletes;

    protected $fillable = [
        'slug',
        'name_ar',
        'name_en',
        'description_ar',
        'description_en',
        'features',
        'price',
        'currency',
        'billing_period_days',
        'consultations_limit',
        'documents_limit',
        'is_featured',
        'is_active',
        'sort_order',
    ];

    /**
     * Match the database defaults so fresh models serialize correctly.
     */
    protected $attributes = [
        'currency' => 'SAR',
        'billing_period_days' => 30,
        'is_featured' => false,
        'is_active' => true,
        'sort_order' => 0,
    ];

    protected function casts(): array
    {
        return [
            'features' => 'array',
            'price' => 'integer',
            'billing_period_days' => 'integer',
            'consultations_limit' => 'integer',
            'documents_limit' => 'integer',
            'is_featured' => 'boolean',
            'is_active' => 'boolean',
            'sort_order' => 'integer',
        ];
    }

    protected static function newFactory(): PackageFactory
    {
        return PackageFactory::new();
    }

    /*
    |--------------------------------------------------------------------------
    | Scopes
    |--------------------------------------------------------------------------
    */

    public function scopeActive(Builder $query): Builder
    {
        return $query->where('is_active', true);
    }

    public function scopeOrdered(Builder $query): Builder
    {
        return $query->orderBy('sort_order')->orderBy('id');
    }

    /*
    |--------------------------------------------------------------------------
    | Helpers
    |--------------------------------------------------------------------------
    */

    /**
     * null consultations_limit means unlimited consultations.
     */
    public function isUnlimited(): bool
    {
        return $this->consultations_limit === null;
    }

    /**
     * The purchase-time snapshot of the display fields, stored on
     * subscriptions and bookings so later package updates or deletes never
     * change what an existing purchase shows.
     *
     * @return array<string, mixed>
     */
    public function snapshot(): array
    {
        return [
            'id' => $this->id,
            'slug' => $this->slug,
            'name_ar' => $this->name_ar,
            'name_en' => $this->name_en,
        ];
    }

    /**
     * The package summary shown on bookings and subscriptions: the stored
     * snapshot wins (it freezes the state at purchase time), the package
     * itself — even a drafted one — fills the gaps for legacy rows.
     *
     * @param  array<string, mixed>|null  $snapshot
     * @return array<string, mixed>|null
     */
    public static function displayFrom(?array $snapshot, ?self $package): ?array
    {
        if ($snapshot === null && $package === null) {
            return null;
        }

        $data = array_replace([
            'id' => $package?->id,
            'slug' => $package?->slug,
            'name_ar' => $package?->name_ar,
            'name_en' => $package?->name_en,
        ], $snapshot ?? []);

        $data['name'] = app()->getLocale() === 'ar' ? $data['name_ar'] : $data['name_en'];

        return $data;
    }

    public function localizedName(?string $locale = null): string
    {
        $locale ??= app()->getLocale();

        return $locale === 'ar' ? $this->name_ar : $this->name_en;
    }

    public function localizedDescription(?string $locale = null): ?string
    {
        $locale ??= app()->getLocale();

        return $locale === 'ar' ? $this->description_ar : $this->description_en;
    }

    /**
     * The features as a flat list of strings in the given locale.
     *
     * @return array<int, string>
     */
    public function localizedFeatures(?string $locale = null): array
    {
        $locale ??= app()->getLocale();

        return collect($this->features ?? [])
            ->map(fn (array $feature) => $feature[$locale] ?? $feature['en'] ?? '')
            ->filter(fn (string $feature) => $feature !== '')
            ->values()
            ->all();
    }

    /*
    |--------------------------------------------------------------------------
    | Relations
    |--------------------------------------------------------------------------
    */

    public function subscriptions(): HasMany
    {
        return $this->hasMany(ClientSubscription::class);
    }

    public function activeSubscriptions(): HasMany
    {
        return $this->hasMany(ClientSubscription::class)
            ->where('status', SubscriptionStatus::Active);
    }
}
