<?php

namespace Modules\Consultants\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * A consultant application submitted from the public "join us" landing form.
 * Status lifecycle: pending -> accepted | rejected.
 */
class JoinRequest extends Model
{
    public const STATUS_PENDING = 'pending';

    public const STATUS_ACCEPTED = 'accepted';

    public const STATUS_REJECTED = 'rejected';

    public const STATUSES = [
        self::STATUS_PENDING,
        self::STATUS_ACCEPTED,
        self::STATUS_REJECTED,
    ];

    /**
     * In-memory default so newly created models report status=pending
     * (the DB column default handles persistence, this covers the response).
     */
    protected $attributes = [
        'status' => self::STATUS_PENDING,
    ];

    protected $fillable = [
        'name',
        'qualification',
        'experience',
        'service_fields',
        'licenses',
        'phone',
        'email',
        'country_city',
        'social_accounts',
        'linkedin',
        'status',
    ];
}
