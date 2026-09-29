<?php

namespace Modules\Bookings\Http\Requests\Client;

use Illuminate\Foundation\Http\FormRequest;

/**
 * Plan §9.5 input. Ownership/activity checks (LOCATION_NOT_OWNED,
 * PAYMENT_METHOD_NOT_OWNED, PACKAGE_INACTIVE, CONSULTANT_INACTIVE) are
 * business rules and live in CreateBookingAction.
 */
class StoreBookingRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'package_id' => ['required', 'integer', 'exists:packages,id'],
            'consultant_id' => ['required', 'integer', 'exists:users,id'],
            'date' => ['required', 'date_format:Y-m-d', 'after_or_equal:today'],
            'time' => ['required', 'date_format:H:i'],
            'client_location_id' => ['required', 'integer', 'exists:client_locations,id'],
            'payment_method_id' => ['nullable', 'integer', 'exists:payment_methods,id'],
            'card_token' => ['nullable', 'string'],
            'save_card' => ['boolean'],
            'client_notes' => ['nullable', 'string', 'max:1000'],
        ];
    }
}
