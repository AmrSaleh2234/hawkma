<?php

namespace Modules\Clients\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\SoftDeletes;

class ClientReview extends Model
{
    use SoftDeletes;

    protected $fillable = [
        'client_id',
        'name',
        'role',
        'rating',
        'text',
        'is_approved',
    ];

    protected function casts(): array
    {
        return [
            'rating' => 'integer',
            'is_approved' => 'boolean',
        ];
    }

    /**
     * Reviews shown publicly on the landing page.
     */
    public function scopeApproved(Builder $query): Builder
    {
        return $query->where('is_approved', true);
    }

    public function client(): BelongsTo
    {
        return $this->belongsTo(Client::class);
    }
}
