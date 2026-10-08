<?php

namespace Modules\Bookings\Http\Requests\Client;

use Illuminate\Foundation\Http\FormRequest;

/**
 * Booking a meeting inside an already-purchased package: the subscription in
 * the route provides the package, so the client only picks consultant, slot,
 * and location. No payment fields — the booking consumes one consultation
 * from the subscription quota (CreateSubscriptionBookingAction).
 */
class StoreSubscriptionBookingRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'consultant_id' => ['required', 'integer', 'exists:users,id'],
            'date' => ['required', 'date_format:Y-m-d', 'after_or_equal:today'],
            'time' => ['required', 'date_format:H:i'],
            'client_location_id' => ['required', 'integer', 'exists:client_locations,id'],
            'client_notes' => ['nullable', 'string', 'max:1000'],
        ];
    }
}
