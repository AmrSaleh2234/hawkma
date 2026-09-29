<?php

namespace Modules\Consultants\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Modules\Consultants\Database\Factories\ConsultantAvailabilityFactory;
use Modules\Users\Models\User;

class ConsultantAvailability extends Model
{
    use HasFactory;

    protected $fillable = [
        'consultant_id',
        'day_of_week',
        'start_time',
        'end_time',
    ];

    protected function casts(): array
    {
        return [
            'day_of_week' => 'integer',
        ];
    }

    protected static function newFactory(): ConsultantAvailabilityFactory
    {
        return ConsultantAvailabilityFactory::new();
    }

    public function consultant(): BelongsTo
    {
        return $this->belongsTo(User::class, 'consultant_id');
    }

    public function scopeForDay(Builder $query, int $dayOfWeek): Builder
    {
        return $query->where('day_of_week', $dayOfWeek)->orderBy('start_time');
    }

    /**
     * The `H:i` representation of a time column (stored as H:i:s).
     */
    public static function toHi(string $time): string
    {
        return substr($time, 0, 5);
    }
}
