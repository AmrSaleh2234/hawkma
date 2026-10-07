<?php

namespace Modules\Reviews\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Modules\Clients\Models\Client;
use Modules\Reviews\Database\Factories\ReviewFactory;
use Modules\Reviews\Enums\ReviewStatus;
use Modules\Users\Models\User;

class Review extends Model
{
    use HasFactory;

    protected $fillable = ['client_id', 'name', 'rating', 'title', 'comment', 'status', 'rejection_reason', 'reviewed_by', 'reviewed_at', 'published_at'];

    protected function casts(): array
    {
        return ['status' => ReviewStatus::class, 'rating' => 'integer', 'reviewed_at' => 'datetime', 'published_at' => 'datetime'];
    }

    protected static function newFactory(): ReviewFactory
    {
        return ReviewFactory::new();
    }

    public function client(): BelongsTo
    {
        return $this->belongsTo(Client::class);
    }

    public function reviewer(): BelongsTo
    {
        return $this->belongsTo(User::class, 'reviewed_by');
    }

    public function scopeApproved(Builder $query): Builder
    {
        return $query->where('status', ReviewStatus::Approved);
    }

    public function scopeOwnedBy(Builder $query, Client $client): Builder
    {
        return $query->where('client_id', $client->id);
    }
}
