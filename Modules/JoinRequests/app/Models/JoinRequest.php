<?php

namespace Modules\JoinRequests\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Modules\JoinRequests\Database\Factories\JoinRequestFactory;
use Modules\JoinRequests\Enums\JoinRequestStatus;
use Modules\Users\Models\User;
use Spatie\MediaLibrary\HasMedia;
use Spatie\MediaLibrary\InteractsWithMedia;

class JoinRequest extends Model implements HasMedia
{
    use HasFactory, InteractsWithMedia;

    protected $fillable = ['name', 'email', 'phone', 'specialization', 'bio', 'linkedin_url', 'status', 'rejection_reason', 'user_id', 'reviewed_by', 'reviewed_at'];

    protected function casts(): array
    {
        return ['status' => JoinRequestStatus::class, 'reviewed_at' => 'datetime'];
    }

    protected static function newFactory(): JoinRequestFactory
    {
        return JoinRequestFactory::new();
    }

    public function registerMediaCollections(): void
    {
        $this->addMediaCollection('cv')->singleFile()->useDisk('local')->acceptsMimeTypes(['application/pdf', 'application/msword', 'application/vnd.openxmlformats-officedocument.wordprocessingml.document']);
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function reviewer(): BelongsTo
    {
        return $this->belongsTo(User::class, 'reviewed_by');
    }
}
