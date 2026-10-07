<?php

namespace Modules\ActivityLogs\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\MorphTo;

class ActivityLog extends Model
{
    public const UPDATED_AT = null;

    /**
     * `system` = model-level CRUD events, `api` = auth/token events.
     */
    public const NAME_SYSTEM = 'system';

    public const NAME_API = 'api';

    protected $fillable = [
        'log_name',
        'module',
        'event',
        'description',
        'subject_type',
        'subject_id',
        'causer_type',
        'causer_id',
        'properties',
        'ip_address',
    ];

    protected function casts(): array
    {
        return [
            'properties' => 'array',
            'created_at' => 'datetime',
        ];
    }

    /*
    |--------------------------------------------------------------------------
    | Relations
    |--------------------------------------------------------------------------
    */

    public function subject(): MorphTo
    {
        return $this->morphTo()->withTrashed();
    }

    public function causer(): MorphTo
    {
        return $this->morphTo()->withTrashed();
    }
}
