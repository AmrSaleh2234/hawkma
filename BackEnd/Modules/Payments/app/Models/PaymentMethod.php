<?php

namespace Modules\Payments\Models;

use Illuminate\Database\Eloquent\Casts\Attribute;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\SoftDeletes;
use Modules\Clients\Models\Client;
use Modules\Payments\Database\Factories\PaymentMethodFactory;

class PaymentMethod extends Model
{
    use HasFactory, SoftDeletes;

    protected $fillable = [
        'client_id',
        'gateway',
        'gateway_token',
        'brand',
        'last_four',
        'exp_month',
        'exp_year',
        'holder_name',
        'is_default',
    ];

    /**
     * The gateway token must never appear in JSON (plan §8.9).
     */
    protected $hidden = [
        'gateway_token',
    ];

    /**
     * Match the database defaults so fresh models serialize correctly.
     */
    protected $attributes = [
        'is_default' => false,
    ];

    protected function casts(): array
    {
        return [
            'is_default' => 'boolean',
            'exp_month' => 'integer',
            'exp_year' => 'integer',
        ];
    }

    protected static function newFactory(): PaymentMethodFactory
    {
        return PaymentMethodFactory::new();
    }

    /*
    |--------------------------------------------------------------------------
    | Helpers
    |--------------------------------------------------------------------------
    */

    public function isExpired(): bool
    {
        $now = now();

        return $this->exp_year < $now->year
            || ($this->exp_year === (int) $now->year && $this->exp_month < $now->month);
    }

    /**
     * "Visa •••• 4242"
     */
    protected function display(): Attribute
    {
        return Attribute::get(
            fn (): string => ucfirst($this->brand).' •••• '.$this->last_four,
        );
    }

    /*
    |--------------------------------------------------------------------------
    | Relations
    |--------------------------------------------------------------------------
    */

    public function client(): BelongsTo
    {
        return $this->belongsTo(Client::class);
    }
}
