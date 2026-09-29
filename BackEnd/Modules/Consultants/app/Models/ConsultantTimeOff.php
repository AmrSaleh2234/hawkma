<?php

namespace Modules\Consultants\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Casts\Attribute;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Modules\Consultants\Database\Factories\ConsultantTimeOffFactory;
use Modules\Users\Models\User;

class ConsultantTimeOff extends Model
{
    use HasFactory;

    protected $fillable = [
        'consultant_id',
        'date',
        'start_time',
        'end_time',
        'reason',
    ];

    protected function casts(): array
    {
        return [
            'date' => 'date',
        ];
    }

    protected static function newFactory(): ConsultantTimeOffFactory
    {
        return ConsultantTimeOffFactory::new();
    }

    public function consultant(): BelongsTo
    {
        return $this->belongsTo(User::class, 'consultant_id');
    }

    /**
     * A null start_time (together with end_time) means the whole day is off.
     */
    protected function isFullDay(): Attribute
    {
        return Attribute::get(fn (): bool => $this->start_time === null);
    }

    public function scopeBetween(Builder $query, string $from, string $to): Builder
    {
        return $query->whereDate('date', '>=', $from)->whereDate('date', '<=', $to);
    }
}
